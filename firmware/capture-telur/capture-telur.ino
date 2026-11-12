/*
  ESP32-CAM AI-Thinker (OV3660 / OV2640) - Fitur Capture Telur Jarak Jauh
  Alur Realtime (MQTT + Fast TCP Upload):
  1. ESP terhubung ke Cloudflare WSS (wss://mqtt.walker880.my.id:443/mqtt).
  2. Saat user klik 'Ambil Foto Candling' di web -> Laravel publish job seketika ke MQTT.
  3. ESP menerima job secara instan (~30ms) -> menyalakan kamera -> relay candling GPIO14 HIGH -> ambil frame JPEG.
  4. Relay candling kembali LOW -> salin citra ke buffer PSRAM -> kamera dinonaktifkan.
  5. Kirim JPEG mentah ke server via POST chunked streaming (sub-2s) ke /api/device/upload?job={id}.
  6. Heartbeat hemat bandwidth (10s) menjaga status device tetap 'Online' di web dashboard.
*/

#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include "esp_camera.h"
#include "esp_log.h"
#include "mqtt_client.h"
#include "esp_crt_bundle.h"
#include "secrets.h"

// ================= KONFIGURASI HARDWARE & JARINGAN =================
#define TX_POWER       WIFI_POWER_19_5dBm   // Daya transmisi WiFi maksimal untuk upload cepat
#define PIN_RELAY      14                   // Relay LED candling (HIGH trigger)
#define MAX_TRIES      3                    // Maksimum percobaan capture saat frame cacat
#ifndef CAMERA_FRAME_SIZE
  #define CAMERA_FRAME_SIZE FRAMESIZE_UXGA  // 1600x1200 native array tanpa sub-sampling noise
#endif
#ifndef JPEG_QUALITY
  #define JPEG_QUALITY 4                    // Kualitas tinggi (kompresi minimal, bebas artifak blok)
#endif

#ifndef MQTT_BROKER_HOST
  #define MQTT_BROKER_HOST "mqtt.walker880.my.id"
#endif
#ifndef MQTT_BROKER_PORT
  #define MQTT_BROKER_PORT 443
#endif

#define MQTT_TOPIC_JOB        "candling/" DEVICE_KEY "/job"
#define MQTT_TOPIC_TELEMETRY  "candling/" DEVICE_KEY "/telemetry"

// ================= PIN KAMERA AI-THINKER =================
#define PWDN_GPIO_NUM  32
#define RESET_GPIO_NUM -1
#define XCLK_GPIO_NUM   0
#define SIOD_GPIO_NUM  26
#define SIOC_GPIO_NUM  27
#define Y9_GPIO_NUM    35
#define Y8_GPIO_NUM    34
#define Y7_GPIO_NUM    39
#define Y6_GPIO_NUM    36
#define Y5_GPIO_NUM    21
#define Y4_GPIO_NUM    19
#define Y3_GPIO_NUM    18
#define Y2_GPIO_NUM     5
#define VSYNC_GPIO_NUM 25
#define HREF_GPIO_NUM  23
#define PCLK_GPIO_NUM  22

// ================= VARIABEL GLOBAL =================
bool camUp = false;
unsigned long lastPoll = 0;

struct CamCfg {
  int ev;
  int gain;
  int q;
  bool valid;
};
CamCfg camCfg = {0, 0, 0, false};

// MQTT & Job State
esp_mqtt_client_handle_t mqttClient = nullptr;
volatile bool mqttConnected = false;
volatile bool hasPendingJob = false;
volatile int  pendingJobId = -1;
volatile int  pendingEv = 500;
volatile int  pendingGain = 0;
volatile int  pendingQ = JPEG_QUALITY;
volatile int  pendingDelayMs = 250;
int lastExecutedJobId = -1;

// ================= KONTROL RELAY =================
// Menyalakan atau mematikan relay LED candling (GPIO14)
void relaySet(bool on) {
  digitalWrite(PIN_RELAY, on ? HIGH : LOW);
}

// ================= MANAJEMEN KAMERA =================
// Membentuk struktur konfigurasi kamera untuk AI-Thinker
static camera_config_t makeConfig(framesize_t fs) {
  camera_config_t c = {};
  c.ledc_channel = LEDC_CHANNEL_0;
  c.ledc_timer   = LEDC_TIMER_0;
  c.pin_d0       = Y2_GPIO_NUM;
  c.pin_d1       = Y3_GPIO_NUM;
  c.pin_d2       = Y4_GPIO_NUM;
  c.pin_d3       = Y5_GPIO_NUM;
  c.pin_d4       = Y6_GPIO_NUM;
  c.pin_d5       = Y7_GPIO_NUM;
  c.pin_d6       = Y8_GPIO_NUM;
  c.pin_d7       = Y9_GPIO_NUM;
  c.pin_xclk     = XCLK_GPIO_NUM;
  c.pin_pclk     = PCLK_GPIO_NUM;
  c.pin_vsync    = VSYNC_GPIO_NUM;
  c.pin_href     = HREF_GPIO_NUM;
  c.pin_sccb_sda = SIOD_GPIO_NUM;
  c.pin_sccb_scl = SIOC_GPIO_NUM;
  c.pin_pwdn     = PWDN_GPIO_NUM;
  c.pin_reset    = RESET_GPIO_NUM;
  c.xclk_freq_hz = 20000000;
  c.pixel_format = PIXFORMAT_JPEG;
  c.grab_mode    = CAMERA_GRAB_WHEN_EMPTY;

  if (psramFound()) {
    c.frame_size   = fs;
    c.jpeg_quality = JPEG_QUALITY;
    c.fb_count     = 1;
    c.fb_location  = CAMERA_FB_IN_PSRAM;
  } else {
    c.frame_size   = FRAMESIZE_SVGA;
    c.jpeg_quality = 12;
    c.fb_count     = 1;
    c.fb_location  = CAMERA_FB_IN_DRAM;
  }
  return c;
}

// Kalibrasi parameter sensor untuk meminimalkan noise pada kondisi candling gelap
void tuneSensor() {
  sensor_t *s = esp_camera_sensor_get();
  if (!s) return;

  s->set_whitebal(s, 1);
  s->set_awb_gain(s, 1);
  s->set_wb_mode(s, 0);
  s->set_bpc(s, 1);               // Black pixel correction (buang hot pixel)
  s->set_wpc(s, 1);               // White pixel correction (buang dead pixel)
  s->set_dcw(s, 1);               // Defective pixel & dark-current correction
  s->set_denoise(s, 1);           // Aktifkan hardware DSP Denoising (menghilangkan noise butiran)
  s->set_raw_gma(s, 0);           // Matikan raw gamma booster agar noise latar belakang tidak terangkat
  s->set_lenc(s, 1);              // Lens shading correction
  s->set_gainceiling(s, GAINCEILING_2X);
  s->set_brightness(s, -1);       // -1 menekan noise floor sensor di area hitam
  s->set_contrast(s, 2);          // +2 memperjelas pembuluh darah & membuat background hitam pekat
  s->set_saturation(s, 1);        // +1 warna pembuluh darah merah-oranye lebih tegas
  s->set_sharpness(s, 2);         // +2 ketajaman tepi pembuluh darah

  // Penyesuaian orientasi dan saturasi khusus sensor OV3660
  if (s->id.PID == OV3660_PID) {
    s->set_vflip(s, 1);
    s->set_saturation(s, -1);
  }
}

// Inisialisasi kamera on-demand dengan fallback resolusi lebih rendah.
bool initCamera() {
  camera_config_t c = makeConfig(CAMERA_FRAME_SIZE);
  if (esp_camera_init(&c) == ESP_OK) {
    tuneSensor();
    camCfg.valid = false;
    return true;
  }

  esp_camera_deinit();
  c = makeConfig(FRAMESIZE_XGA);
  if (esp_camera_init(&c) == ESP_OK) {
    tuneSensor();
    camCfg.valid = false;
    return true;
  }

  esp_camera_deinit();
  c = makeConfig(FRAMESIZE_SVGA);
  if (esp_camera_init(&c) == ESP_OK) {
    tuneSensor();
    camCfg.valid = false;
    return true;
  }

  return false;
}

// Mengaktifkan kamera jika belum aktif
bool camStart() {
  if (camUp) return true;
  camUp = initCamera();
  return camUp;
}

// Mematikan kamera untuk menghemat daya dan mencegah interferensi Wi-Fi
void camStop() {
  if (!camUp) return;
  esp_camera_deinit();
  camUp = false;
  camCfg.valid = false;
}

// Pemulihan kamera saat frame berulang kali gagal
void recoverCamera() {
  esp_camera_deinit();
  delay(300);
  camUp = initCamera();
}

// Penerapan nilai exposure, gain, dan kualitas JPEG yang diminta server
bool applyCamSettings(int ev, int gain, int q) {
  if (camCfg.valid && camCfg.ev == ev && camCfg.gain == gain && camCfg.q == q) {
    return false;
  }
  sensor_t *s = esp_camera_sensor_get();
  if (!s) return false;

  s->set_quality(s, q);
  s->set_exposure_ctrl(s, 0);
  s->set_aec_value(s, ev);
  s->set_gain_ctrl(s, 0);
  s->set_agc_gain(s, constrain(gain, 0, 10)); // Batasi gain analog agar tidak menimbulkan noise berlebih

  camCfg = {ev, gain, q, true};
  return true;
}

// Verifikasi integritas format JPEG (marker SOI FF D8 dan EOI FF D9)
static bool jpegValid(camera_fb_t *fb) {
  if (!fb || fb->format != PIXFORMAT_JPEG || fb->len < 1000) {
    return false;
  }
  return fb->buf[0] == 0xFF && fb->buf[1] == 0xD8 &&
         fb->buf[fb->len - 2] == 0xFF && fb->buf[fb->len - 1] == 0xD9;
}

// Eksekusi pemotretan telur candling
bool captureEgg(int ev, int gain, int q, int settle, uint8_t **out, size_t *outLen) {
  if (!camStart()) {
    relaySet(false);
    return false;
  }

  // 1. Nyalakan lampu relay candling DULU agar sensor menerima cahaya penuh
  relaySet(true);
  delay(max(settle, 350)); // Beri waktu lampu LED stabil pada intensitas puncak

  // 2. Terapkan parameter sensor
  applyCamSettings(ev, gain, q);

  camera_fb_t *fb = NULL;
  int tries = 0;
  int qUsed = q;

  while (!fb && tries < MAX_TRIES) {
    tries++;
    // Buang 3 frame awal setelah lampu menyala agar AGC/AEC internal dan AWB stabil
    for (int i = 0; i < 3; i++) {
      camera_fb_t *old = esp_camera_fb_get();
      if (old) esp_camera_fb_return(old);
      delay(40);
    }

    camera_fb_t *t = esp_camera_fb_get();
    if (jpegValid(t)) {
      fb = t;
      break;
    }
    if (t) esp_camera_fb_return(t);
    Serial.printf("[CAP] Percobaan %d gagal\n", tries);

    if (tries == MAX_TRIES - 1) {
      recoverCamera();
      if (!camUp) break;
      applyCamSettings(ev, gain, qUsed);
    } else {
      qUsed = min(qUsed + 2, 12);
      sensor_t *s = esp_camera_sensor_get();
      if (s) s->set_quality(s, qUsed);
      camCfg.valid = false;
    }
    delay(80);
  }

  // Pastikan relay SELALU mati setelah pemotretan
  relaySet(false);

  bool ok = false;
  if (fb) {
    *out = (uint8_t*)(psramFound() ? ps_malloc(fb->len) : malloc(fb->len));
    if (*out) {
      memcpy(*out, fb->buf, fb->len);
      *outLen = fb->len;
      ok = true;
    }
    esp_camera_fb_return(fb);
  }

  // Matikan modul kamera sebelum mulai upload agar radio WiFi maksimal
  camStop();
  return ok;
}

// ================= PARSING PROTOKOL =================
static const char *jsonValue(const String &json, const char *key) {
  char pat[40];
  snprintf(pat, sizeof(pat), "\"%s\":", key);
  int i = json.indexOf(pat);
  return i < 0 ? nullptr : json.c_str() + i + strlen(pat);
}

static int getInt(const String &s, const char *key, int def) {
  String k = String(key) + "=";
  int i = s.indexOf(k);
  if (i < 0) return def;
  i += k.length();
  int j = s.indexOf(';', i);
  if (j < 0) j = s.length();
  return s.substring(i, j).toInt();
}

void scheduleJob(const String &msg) {
  int job = 0;
  int ev = 500;
  int gain = 0;
  int q = JPEG_QUALITY;
  int delayMs = 250;

  if (msg.indexOf('{') >= 0) {
    const char *pJob = jsonValue(msg, "job");
    const char *pExp = jsonValue(msg, "exp");
    const char *pGain = jsonValue(msg, "gain");
    const char *pQ = jsonValue(msg, "q");
    const char *pDelay = jsonValue(msg, "delay");

    if (pJob) job = atoi(pJob);
    if (pExp) ev = atoi(pExp);
    if (pGain) gain = atoi(pGain);
    if (pQ) q = atoi(pQ);
    if (pDelay) delayMs = atoi(pDelay);
  } else {
    job = getInt(msg, "job", 0);
    ev = getInt(msg, "exp", 500);
    gain = getInt(msg, "gain", 0);
    q = getInt(msg, "q", JPEG_QUALITY);
    delayMs = getInt(msg, "delay", 250);
  }

  if (job <= 0) return;
  if (job == lastExecutedJobId) {
    Serial.printf("[JOB %d] Dilewati (sudah diproses)\n", job);
    return;
  }

  pendingJobId = job;
  pendingEv = constrain(ev, 0, 1200);
  pendingGain = constrain(gain, 0, 30);
  pendingQ = constrain(q, 4, 15);
  pendingDelayMs = constrain(delayMs, 100, 3000);
  hasPendingJob = true;

  Serial.printf("[JOB %d] Diterima: exp=%d gain=%d q=%d delay=%d\n",
                pendingJobId, pendingEv, pendingGain, pendingQ, pendingDelayMs);
}

// ================= KOMUNIKASI TCP CEPAT KE SERVER =================
static const char *getServerHost() {
  return "iot.walker880.my.id";
}

// Mengirim data foto mentah (chunked raw TCP, sub-2s) ke server
bool sendResult(int job, const uint8_t *buf, size_t len, bool fail) {
  WiFiClient client;
  client.setNoDelay(true);
  client.setTimeout(10);

  const char *host = getServerHost();
  const uint16_t port = 80;

  if (!client.connect(host, port)) {
    Serial.printf("[UPLOAD] Gagal konek ke %s:%d\n", host, port);
    return false;
  }

  uint32_t t0 = millis();
  String path = "/api/device/upload?job=" + String(job) + (fail ? "&fail=1" : "");
  size_t bodyLen = fail ? 4 : len;

  client.printf("POST %s HTTP/1.1\r\n", path.c_str());
  client.printf("Host: %s\r\n", host);
  client.print("User-Agent: ESP32HTTPClient\r\n");
  client.printf("X-Device-Key: %s\r\n", DEVICE_KEY);
  client.printf("X-RSSI: %d\r\n", WiFi.RSSI());
  client.printf("Content-Type: %s\r\n", fail ? "text/plain" : "image/jpeg");
  client.printf("Content-Length: %u\r\n", (unsigned)bodyLen);
  client.print("Connection: close\r\n\r\n");

  if (fail) {
    client.print("fail");
  } else if (buf && len > 0) {
    size_t remaining = len;
    size_t offset = 0;
    while (remaining > 0 && client.connected()) {
      size_t toSend = (remaining > 2048) ? 2048 : remaining;
      size_t written = client.write(buf + offset, toSend);
      if (written > 0) {
        offset += written;
        remaining -= written;
      } else {
        delay(5);
      }
    }
  }

  // Tunggu response status
  uint32_t waitResp = millis();
  while (client.connected() && !client.available() && millis() - waitResp < 8000) {
    delay(10);
  }

  int code = -1;
  String statusLine = client.readStringUntil('\n');
  if (statusLine.startsWith("HTTP/1.")) {
    code = statusLine.substring(9, 12).toInt();
  }
  client.stop();

  uint32_t elapsed = millis() - t0;
  Serial.printf("[UPLOAD] job=%d HTTP %d | %u KB | kirim %u ms\n",
                job, code, (unsigned)(len / 1024), elapsed);
  return code == 200;
}

// Heartbeat & Fallback Polling (10s saat MQTT aktif, 3s saat MQTT offline)
void pollServer() {
  WiFiClient client;
  client.setNoDelay(true);
  client.setTimeout(5);

  const char *host = getServerHost();
  const uint16_t port = 80;

  if (!client.connect(host, port)) {
    return;
  }

  client.print("GET /api/device/poll HTTP/1.1\r\n");
  client.printf("Host: %s\r\n", host);
  client.print("User-Agent: ESP32HTTPClient\r\n");
  client.printf("X-Device-Key: %s\r\n", DEVICE_KEY);
  client.printf("X-RSSI: %d\r\n", WiFi.RSSI());
  client.print("Connection: close\r\n\r\n");

  uint32_t waitResp = millis();
  while (client.connected() && !client.available() && millis() - waitResp < 4000) {
    delay(10);
  }

  int code = -1;
  String statusLine = client.readStringUntil('\n');
  if (statusLine.startsWith("HTTP/1.")) {
    code = statusLine.substring(9, 12).toInt();
  }

  while (client.connected()) {
    String line = client.readStringUntil('\n');
    if (line == "\r" || line.length() == 0) break;
  }

  String body = "";
  while (client.available()) {
    body += (char)client.read();
  }
  client.stop();

  if (code == 200 && body.startsWith("job=")) {
    scheduleJob(body);
  }
}

// ================= MQTT PROTOKOL REALTIME (Cloudflare WSS Port 443) =================
static void mqttEventHandler(void *handler_args, esp_event_base_t base, int32_t event_id, void *event_data) {
  esp_mqtt_event_handle_t event = (esp_mqtt_event_handle_t)event_data;
  switch ((esp_mqtt_event_id_t)event_id) {
    case MQTT_EVENT_CONNECTED:
      mqttConnected = true;
      Serial.println("[MQTT] Terhubung ke Cloudflare WSS (Port 443)!");
      esp_mqtt_client_subscribe(mqttClient, MQTT_TOPIC_JOB, 1);
      esp_mqtt_client_publish(mqttClient, "candling/" DEVICE_KEY "/status", "{\"online\":true}", 0, 1, 1);
      break;

    case MQTT_EVENT_DISCONNECTED:
      mqttConnected = false;
      Serial.println("[MQTT] Terputus, auto-reconnect aktif...");
      break;

    case MQTT_EVENT_DATA: {
      if (event->data && event->data_len > 0) {
        char payload[512] = {0};
        int dlen = min((int)sizeof(payload) - 1, event->data_len);
        memcpy(payload, event->data, dlen);
        payload[dlen] = '\0';
        Serial.printf("[MQTT RECV] %s\n", payload);
        scheduleJob(String(payload));
      }
      break;
    }

    case MQTT_EVENT_ERROR:
      Serial.println("[MQTT] Terjadi event error");
      break;

    default:
      break;
  }
}

void initMqtt() {
  if (mqttClient) return;
  Serial.printf("[MQTT] Inisialisasi... Free Heap: %u\n", ESP.getFreeHeap());
  esp_mqtt_client_config_t mqtt_cfg = {};
  mqtt_cfg.broker.address.hostname = MQTT_BROKER_HOST;
  mqtt_cfg.broker.address.port = MQTT_BROKER_PORT;
  mqtt_cfg.broker.address.transport = MQTT_TRANSPORT_OVER_WSS;
  mqtt_cfg.broker.address.path = "/mqtt";
  mqtt_cfg.broker.verification.crt_bundle_attach = esp_crt_bundle_attach;
  mqtt_cfg.session.last_will.topic = "candling/" DEVICE_KEY "/status";
  mqtt_cfg.session.last_will.msg = "{\"online\":false}";
  mqtt_cfg.session.last_will.msg_len = 16;
  mqtt_cfg.session.last_will.qos = 1;
  mqtt_cfg.session.last_will.retain = 1;
  mqtt_cfg.task.stack_size = 10240;
  mqtt_cfg.buffer.size = 1024;
  mqtt_cfg.buffer.out_size = 1024;
  mqtt_cfg.network.reconnect_timeout_ms = 8000;
  mqtt_cfg.network.disable_auto_reconnect = false;

  mqttClient = esp_mqtt_client_init(&mqtt_cfg);
  if (mqttClient) {
    esp_mqtt_client_register_event(mqttClient, MQTT_EVENT_ANY, mqttEventHandler, NULL);
    esp_err_t err = esp_mqtt_client_start(mqttClient);
    Serial.printf("[MQTT] Client dimulai (err: %d)\n", (int)err);
  } else {
    Serial.println("[MQTT] Gagal alokasi client");
  }
}

void processPendingJob() {
  if (!hasPendingJob) return;
  hasPendingJob = false;

  int job = pendingJobId;
  int ev = pendingEv;
  int gain = pendingGain;
  int q = pendingQ;
  int delayMs = pendingDelayMs;
  lastExecutedJobId = job;

  Serial.printf("[EXEC JOB %d] exp=%d gain=%d q=%d delay=%d\n", job, ev, gain, q, delayMs);

  if (mqttConnected) {
    char jobMsg[64];
    snprintf(jobMsg, sizeof(jobMsg), "{\"job\":%d,\"status\":\"capturing\"}", job);
    esp_mqtt_client_publish(mqttClient, MQTT_TOPIC_TELEMETRY, jobMsg, 0, 0, 0);
  }

  uint8_t *img = NULL;
  size_t len = 0;
  unsigned long t0 = millis();

  if (captureEgg(ev, gain, q, delayMs, &img, &len)) {
    Serial.printf("[JOB %d] foto %uKB dalam %lums\n", job, (unsigned)(len / 1024), millis() - t0);
    sendResult(job, img, len, false);
    if (img) free(img);
    if (mqttConnected) {
      char doneMsg[64];
      snprintf(doneMsg, sizeof(doneMsg), "{\"job\":%d,\"status\":\"done\"}", job);
      esp_mqtt_client_publish(mqttClient, MQTT_TOPIC_TELEMETRY, doneMsg, 0, 0, 0);
    }
  } else {
    Serial.printf("[JOB %d] Gagal memotret, melapor ke server\n", job);
    sendResult(job, NULL, 0, true);
    if (mqttConnected) {
      char failMsg[64];
      snprintf(failMsg, sizeof(failMsg), "{\"job\":%d,\"status\":\"failed\"}", job);
      esp_mqtt_client_publish(mqttClient, MQTT_TOPIC_TELEMETRY, failMsg, 0, 0, 0);
    }
  }
}

// ================= KONEKSI WIFI =================
void connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  WiFi.setTxPower(TX_POWER);

  Serial.print("Menghubungkan ke WiFi");
  unsigned long t0 = millis();
  while (WiFi.status() != WL_CONNECTED) {
    delay(300);
    Serial.print(".");
    if (millis() - t0 > 30000) {
      Serial.println("\nWiFi gagal terhubung, melakukan restart...");
      ESP.restart();
    }
  }
  Serial.printf("\nWiFi terhubung. IP: %s | RSSI: %d dBm\n",
                WiFi.localIP().toString().c_str(),
                WiFi.RSSI());
}

// ================= SETUP & LOOP =================
void setup() {
  Serial.begin(115200);

  // Redam log internal GPIO ISR service
  esp_log_level_set("gpio", ESP_LOG_NONE);

  // Inisialisasi pin relay candling (harus LOW saat boot)
  pinMode(PIN_RELAY, OUTPUT);
  digitalWrite(PIN_RELAY, LOW);

  if (!psramFound()) {
    Serial.println("PERINGATAN: PSRAM tidak terdeteksi! Pastikan PSRAM diaktifkan pada menu Board.");
  }

  // Uji coba kamera satu kali saat startup (Tuning & Logika asli tidak diubah)
  if (!camStart()) {
    Serial.println("Kamera GAGAL inisialisasi awal, restart...");
    delay(3000);
    ESP.restart();
  }

  sensor_t *s = esp_camera_sensor_get();
  if (s) {
    Serial.printf("Sensor kamera terdeteksi, PID: 0x%04X\n", s->id.PID);
  }
  camStop();

  connectWiFi();

  // Sinkronisasi waktu NTP untuk validasi TLS sertifikat Cloudflare
  configTime(0, 0, "pool.ntp.org", "time.google.com");
  Serial.println("[NTP] Sinkronisasi waktu...");
  delay(1000);

  // Inisialisasi MQTT WSS (Port 443)
  initMqtt();

  Serial.println("ESP32-CAM Candling siap, mendengarkan tugas via MQTT & Poll...");
}

void loop() {
  if (WiFi.status() != WL_CONNECTED) {
    WiFi.reconnect();
    delay(2000);
    return;
  }

  // 1. Eksekusi job jika ada yang masuk (dari MQTT atau fallback poll)
  if (hasPendingJob) {
    processPendingJob();
  }

  // 2. Heartbeat interval: 10 detik saat MQTT aktif, 3 detik saat MQTT terputus
  uint32_t pollInterval = mqttConnected ? 10000 : 3000;
  if (millis() - lastPoll >= pollInterval) {
    lastPoll = millis();
    pollServer();
  }

  // 3. Heartbeat telemetri MQTT tiap 5 detik
  static uint32_t lastMqttHeartbeat = 0;
  if (mqttConnected && millis() - lastMqttHeartbeat >= 5000) {
    lastMqttHeartbeat = millis();
    char tele[128];
    snprintf(tele, sizeof(tele), "{\"online\":true,\"rssi\":%d,\"heap\":%u}", WiFi.RSSI(), ESP.getFreeHeap());
    esp_mqtt_client_publish(mqttClient, MQTT_TOPIC_TELEMETRY, tele, 0, 0, 0);
  }

  delay(20);
}
