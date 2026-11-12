/**
 * ====================================================================================
 * INKUBATOR PENETAS TELUR - ESP32-CAM (AI-Thinker, OV2640 / OV3660)
 *
 * Pembagian kerja dual-core:
 *   Core 1 (loop)      : web server lokal, DHT22, termostat, relay, motor, LCD.
 *   Core 0 (cloudTask) : upload foto + polling kontrol ke server.
 *
 * Anti-lag jangka panjang:
 *   1. FastWebServer memotong batas tunggu socket kosong Chrome (preconnect) dari 5000 ms ke 200 ms.
 *   2. esp_wifi_set_ps(WIFI_PS_NONE) dipanggil setelah WiFi.begin() agar radio tidak masuk sleep.
 *   3. cameraMutex hanya dikunci saat jepret hardware (~0.5 s), TIDAK dikunci saat upload/poll HTTPS.
 *   4. Kamera dinyalakan on-demand (UXGA 1600x1200) lalu langsung camStop() sebelum kirim WiFi.
 * ====================================================================================
 */

#include "esp_camera.h"
#include "esp_wifi.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <Preferences.h>
#include <WebServer.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include "DHT.h"
#include "esp_log.h"
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"
#include "mqtt_client.h"
#include "esp_crt_bundle.h"

// ─── KONFIGURASI ──────────────────────────────────────────────────────────────────
#if __has_include("secrets.h")
  #include "secrets.h"
#endif
#ifndef WIFI_SSID
  #define WIFI_SSID  "Telur"
#endif
#ifndef WIFI_PASS
  #define WIFI_PASS  "12345678"
#endif
#ifndef SERVER_URL
  #define SERVER_URL "http://iot.walker880.my.id"
#endif
#ifndef MQTT_BROKER_URI
  #define MQTT_BROKER_URI "wss://mqtt.walker880.my.id:443/mqtt"
#endif
#ifndef DEVICE_KEY
  #define DEVICE_KEY "EggCamSecretKey_9876543210_abcdef1234567890"
#endif

#define MQTT_TOPIC_CONTROL    "incubator/" DEVICE_KEY "/control"
#define MQTT_TOPIC_TELEMETRY  "incubator/" DEVICE_KEY "/telemetry"
#ifndef CAMERA_FRAME_SIZE
  #define CAMERA_FRAME_SIZE FRAMESIZE_UXGA  // 1600x1200 (UXGA)
#endif
#ifndef JPEG_QUALITY
  #define JPEG_QUALITY 4                    // Kualitas mentok tertinggi (4 = kompresi minimal, sangat tajam)
#endif

// Pin AI-Thinker
#define PIN_I2C_SDA      14
#define PIN_I2C_SCL      13
#define PIN_DHT          15
#define PIN_RELAY_HEATER 4    // berbagi dengan LED flash onboard
#define PIN_RELAY_MOTOR  2
#define RELAY_ON         HIGH
#define RELAY_OFF        LOW

#define PWDN_GPIO_NUM    32
#define RESET_GPIO_NUM   -1
#define XCLK_GPIO_NUM     0
#define SIOD_GPIO_NUM    26
#define SIOC_GPIO_NUM    27
#define Y9_GPIO_NUM      35
#define Y8_GPIO_NUM      34
#define Y7_GPIO_NUM      39
#define Y6_GPIO_NUM      36
#define Y5_GPIO_NUM      21
#define Y4_GPIO_NUM      19
#define Y3_GPIO_NUM      18
#define Y2_GPIO_NUM       5
#define VSYNC_GPIO_NUM   25
#define HREF_GPIO_NUM    23
#define PCLK_GPIO_NUM    22

// Batas waktu & keamanan
const uint32_t RELAY_MIN_SWITCH_MS   = 3000;   // relay minimal 3 s di satu status (anti-kedip cepat)
const uint32_t PHOTO_LIGHT_HOLD_MS   = 3500;   // lampu warm & LED menyala 3.5 s saat jadwal foto tiba
const uint32_t PHOTO_LIGHT_WARMUP_MS = 450;    // pemanasan filamen sebelum jepret
const uint32_t SENSOR_SETTLE_MS      = 220;    // waktu stabilisasi AWB/AEC sensor
const float    TEMP_HARD_LIMIT       = 40.5f;  // di atas ini lampu dipaksa mati, mode apa pun
const uint32_t CONTROL_POLL_MS       = 3000;
const uint32_t UPLOAD_MIN_S          = 15;     // mengikuti interval minimal di web (15 detik)
const uint32_t UPLOAD_MAX_S          = 3600;
const uint8_t  DHT_MAX_FAILS         = 3;      // NaN sesaat dari DHT tidak memutus relay
const int      CAPTURE_TRIES         = 3;

enum LampMode : uint8_t { LAMP_AUTO, LAMP_ON, LAMP_OFF };
static const char *const LAMP_MODE_NAME[] = {"auto", "on", "off"};

// WebServer yang memutus socket preconnect kosong Chrome dalam 200 ms (bukan 5000 ms).
class FastWebServer : public WebServer {
public:
  using WebServer::WebServer;
  void handleClient() {
    if (_currentStatus == HC_WAIT_READ && !_currentClient.available() &&
        millis() - _statusChange > 200) {
      _currentClient = NetworkClient();
      _currentStatus = HC_NONE;
    }
    WebServer::handleClient();
  }
};

// ─── STATE BERSAMA ────────────────────────────────────────────────────────────────
volatile float    tempSetpointMin   = 37.0f;
volatile float    tempSetpointMax   = 38.0f;
volatile uint8_t  lampMode          = LAMP_AUTO;
volatile uint32_t motorIntervalMs   = 4UL * 3600000UL;
volatile uint32_t motorDurationMs   = 15000UL;
volatile uint32_t uploadIntervalMs  = 120000UL;
volatile bool     motorRequest      = false;
volatile bool     manualLight       = false;
volatile bool     manualCaptureRequest = false;

volatile float    currentTemp = NAN;
volatile float    currentHum  = NAN;
volatile bool     heaterState = false;
volatile bool     isMotorRunning = false;

volatile int      lastUploadCode  = 0;
volatile uint32_t lastUploadTime  = 0;
volatile uint32_t lastUploadBytes = 0;

volatile uint32_t photoLightUntil   = 0;
volatile uint32_t localWebBusyUntil = 0;
bool     heaterPinOn = false;
uint32_t heaterPinChangedAt = 0;
uint32_t motorStartTime = 0;
uint32_t lastMotorTurn  = 0;

char     lastMotorCommandId[48] = "";
char     lastCaptureCommandId[48] = "";
uint32_t storedUploadSeconds = 120;

portMUX_TYPE      heaterMux   = portMUX_INITIALIZER_UNLOCKED;
SemaphoreHandle_t cameraMutex = NULL;          // HANYA mengunci saat kamera fisik memotret
bool              camUp       = false;

// Dua buffer PSRAM terpisah agar web lokal dan upload cloud tidak pernah saling tunggu
struct PhotoBuffer {
  uint8_t *buf = nullptr;
  size_t   len = 0;
  size_t   cap = 0;
};
PhotoBuffer localPhoto;
PhotoBuffer cloudPhoto;

DHT dht(PIN_DHT, DHT22);
LiquidCrystal_I2C *lcd = nullptr;
FastWebServer server(80);

static inline bool useTls() { return strncmp(SERVER_URL, "https", 5) == 0; }

// ─── RELAY LAMPU PEMANAS ──────────────────────────────────────────────────────────
void applyHeaterPin() {
  uint32_t now = millis();
  float t = currentTemp;
  bool overheat = isfinite(t) && t >= TEMP_HARD_LIMIT;
  bool tempValid = isfinite(t) && t > 0;
  bool canHeat = tempValid && !overheat && heaterState;
  bool canLight = !overheat && (!tempValid || t < tempSetpointMax + 0.5f) &&
                  (manualLight || (int32_t)(photoLightUntil - now) > 0);
  bool want = canHeat || canLight;
  if (want == heaterPinOn) return;
  if (!overheat && heaterPinChangedAt != 0 && now - heaterPinChangedAt < RELAY_MIN_SWITCH_MS) return;

  heaterPinOn = want;
  heaterPinChangedAt = now;
  digitalWrite(PIN_RELAY_HEATER, want ? RELAY_ON : RELAY_OFF);
}

void refreshHeaterPin() {
  portENTER_CRITICAL(&heaterMux);
  applyHeaterPin();
  portEXIT_CRITICAL(&heaterMux);
}

uint32_t requestPhotoLight() {
  portENTER_CRITICAL(&heaterMux);
  bool wasOn = heaterPinOn;
  photoLightUntil = millis() + PHOTO_LIGHT_HOLD_MS;
  applyHeaterPin();
  bool nowOn = heaterPinOn;
  portEXIT_CRITICAL(&heaterMux);
  return (!wasOn && nowOn) ? PHOTO_LIGHT_WARMUP_MS : 0;
}

void updateThermostat() {
  float t = currentTemp;
  bool h = heaterState;
  if (!isfinite(t) || t <= 0)      h = false;
  else if (lampMode == LAMP_ON)    h = true;
  else if (lampMode == LAMP_OFF)   h = false;
  else if (t < tempSetpointMin)    h = true;
  else if (t >= tempSetpointMax)   h = false;
  heaterState = h;
  refreshHeaterPin();
}

// ─── MOTOR PEMBALIK TELUR ─────────────────────────────────────────────────────────
void updateMotor(uint32_t now) {
  if (isMotorRunning) {
    if (now - motorStartTime >= motorDurationMs) {
      digitalWrite(PIN_RELAY_MOTOR, RELAY_OFF);
      isMotorRunning = false;
      lastMotorTurn = now;
      Serial.println("[MOTOR] selesai");
    }
    return;
  }
  if (motorRequest || now - lastMotorTurn >= motorIntervalMs) {
    motorRequest = false;
    digitalWrite(PIN_RELAY_MOTOR, RELAY_ON);
    isMotorRunning = true;
    motorStartTime = now;
    Serial.println("[MOTOR] berputar");
  }
}

// ─── KAMERA (UXGA 1600x1200, FB TUNGGAL GRAB_WHEN_EMPTY = TANPA RESET AWB & TANPA GANGGUAN DMA) ──
static camera_config_t makeConfig(framesize_t fs) {
  camera_config_t c = {};
  c.ledc_channel = LEDC_CHANNEL_0;
  c.ledc_timer   = LEDC_TIMER_0;
  c.pin_d0 = Y2_GPIO_NUM; c.pin_d1 = Y3_GPIO_NUM; c.pin_d2 = Y4_GPIO_NUM; c.pin_d3 = Y5_GPIO_NUM;
  c.pin_d4 = Y6_GPIO_NUM; c.pin_d5 = Y7_GPIO_NUM; c.pin_d6 = Y8_GPIO_NUM; c.pin_d7 = Y9_GPIO_NUM;
  c.pin_xclk = XCLK_GPIO_NUM; c.pin_pclk = PCLK_GPIO_NUM;
  c.pin_vsync = VSYNC_GPIO_NUM; c.pin_href = HREF_GPIO_NUM;
  c.pin_sccb_sda = SIOD_GPIO_NUM; c.pin_sccb_scl = SIOC_GPIO_NUM;
  c.pin_pwdn = PWDN_GPIO_NUM; c.pin_reset = RESET_GPIO_NUM;
  c.xclk_freq_hz = 20000000;
  c.pixel_format = PIXFORMAT_JPEG;
  // GRAB_WHEN_EMPTY + fb_count=1: DMA berhenti otomatis saat tidak memotret (WiFi kencang),
  // tapi sensor tetap hidup sehingga Auto White Balance (AWB) tidak pernah reset jadi hijau.
  c.grab_mode    = CAMERA_GRAB_WHEN_EMPTY;
  bool psram = psramFound();
  c.frame_size   = psram ? fs : FRAMESIZE_SVGA;
  c.jpeg_quality = psram ? JPEG_QUALITY : 12;
  c.fb_count     = 1;
  c.fb_location  = psram ? CAMERA_FB_IN_PSRAM : CAMERA_FB_IN_DRAM;
  return c;
}

void tuneSensor() {
  sensor_t *s = esp_camera_sensor_get();
  if (!s) return;
  s->set_whitebal(s, 1);
  s->set_awb_gain(s, 1);
  s->set_wb_mode(s, 0);                  // Auto White Balance
  s->set_exposure_ctrl(s, 1);            // Auto Exposure aktif
  s->set_aec2(s, 0);                     // Matikan DSP aec2 (penyebab bayangan terangkat jadi hijau)
  s->set_ae_level(s, -2);                // Target eksposur -2: cegah cangkang putih silau/overexposed, detail retakan tajam
  s->set_gain_ctrl(s, 1);
  s->set_gainceiling(s, GAINCEILING_2X); // Batasi gain 2X (menghilangkan noise)
  s->set_bpc(s, 1);                      // Bad pixel correction
  s->set_wpc(s, 1);                      // White pixel correction
  s->set_raw_gma(s, 1);
  s->set_lenc(s, 1);                     // Koreksi vignetting lensa sudut
  s->set_brightness(s, -1);              // Kecerahan -1 agar highlight cangkang tidak terbakar/washed out
  s->set_contrast(s, 1);                 // Kontras seimbang (+1) memperjelas garis retak tanpa memotong highlight
  s->set_saturation(s, 1);               // Saturasi warna hidup (+1)
  s->set_sharpness(s, 2);                // Ketajaman maksimal (+2) untuk menonjolkan garis tepi dan retakan
  s->set_vflip(s, 1);
}

bool camStart() {
  if (camUp) return true;
  const framesize_t sizes[] = {FRAMESIZE_UXGA, FRAMESIZE_SXGA, FRAMESIZE_XGA, FRAMESIZE_SVGA};
  for (framesize_t fs : sizes) {
    camera_config_t c = makeConfig(fs);
    if (esp_camera_init(&c) == ESP_OK) {
      tuneSensor();
      // Buang beberapa frame saat start agar AWB selesai kalibrasi dari hijau ke warna normal
      for (int i = 0; i < 4; i++) {
        delay(80);
        camera_fb_t *fb = esp_camera_fb_get();
        if (fb) esp_camera_fb_return(fb);
      }
      Serial.printf("[CAM] siap di framesize=%d quality=%d\n", (int)fs, (int)c.jpeg_quality);
      return camUp = true;
    }
    esp_camera_deinit();
  }
  return false;
}

void camStop() {
  if (!camUp) return;
  esp_camera_deinit();
  camUp = false;
}

static bool jpegValid(const camera_fb_t *fb) {
  return fb && fb->format == PIXFORMAT_JPEG && fb->len >= 1000 &&
         fb->buf[0] == 0xFF && fb->buf[1] == 0xD8 &&
         fb->buf[fb->len - 2] == 0xFF && fb->buf[fb->len - 1] == 0xD9;
}

static bool saveToBuffer(PhotoBuffer &dst, const uint8_t *src, size_t len) {
  if (len > dst.cap) {
    if (dst.buf) free(dst.buf);
    dst.cap = max(len + 16384U, (size_t)163840U);
    dst.buf = (uint8_t *)(psramFound() ? ps_malloc(dst.cap) : malloc(dst.cap));
    if (!dst.buf) {
      dst.cap = 0;
      dst.len = 0;
      return false;
    }
  }
  memcpy(dst.buf, src, len);
  dst.len = len;
  return true;
}

// Ambil foto UXGA ke buffer tujuan. Sensor tetap hidup (AWB tetap akurat/tidak hijau),
// dan dengan fb_count=1 + GRAB_WHEN_EMPTY, DMA langsung berhenti setelah frame diambil.
bool capturePhotoTo(PhotoBuffer &target) {
  if (xSemaphoreTake(cameraMutex, pdMS_TO_TICKS(5000)) != pdTRUE) return false;

  uint32_t warmup = requestPhotoLight();
  if (!camStart()) {
    xSemaphoreGive(cameraMutex);
    return false;
  }

  if (warmup) delay(warmup);

  bool ok = false;
  int qUsed = JPEG_QUALITY;
  for (int t = 0; t < CAPTURE_TRIES; t++) {
    // Buang 1 frame lama di buffer tunggal (dan 1 frame lagi jika lampu baru menyala)
    int flushCount = (warmup && t == 0) ? 2 : 1;
    for (int i = 0; i < flushCount; i++) {
      camera_fb_t *old = esp_camera_fb_get();
      if (old) esp_camera_fb_return(old);
      if (warmup) delay(60);
    }

    camera_fb_t *fb = esp_camera_fb_get();
    if (jpegValid(fb)) {
      ok = saveToBuffer(target, fb->buf, fb->len);
      esp_camera_fb_return(fb);
      break;
    }
    if (fb) esp_camera_fb_return(fb);
    qUsed = min(qUsed + 2, 12);
    sensor_t *s = esp_camera_sensor_get();
    if (s) s->set_quality(s, qUsed);
    delay(60);
  }
  if (qUsed != JPEG_QUALITY) {
    sensor_t *s = esp_camera_sensor_get();
    if (s) s->set_quality(s, JPEG_QUALITY);
  }

  if (!ok) {
    // Reset hanya jika sensor gagal memberi frame valid
    camStop();
  }
  xSemaphoreGive(cameraMutex);
  return ok;
}

// ─── PARSER JSON RINGAN ───────────────────────────────────────────────────────────
static const char *jsonValue(const String &json, const char *key) {
  char pat[40];
  snprintf(pat, sizeof(pat), "\"%s\":", key);
  int i = json.indexOf(pat);
  return i < 0 ? nullptr : json.c_str() + i + strlen(pat);
}

static bool jsonNumber(const String &json, const char *key, float &out) {
  const char *p = jsonValue(json, key);
  if (!p || *p == 'n') return false;
  out = strtof(p, nullptr);
  return true;
}

void applyCloudCommands(const String &json) {
  // Manual capture via MQTT / Web (deduplikasi ID agar tidak looping)
  const char *capReq = jsonValue(json, "triggerCapture");
  const char *capAct = jsonValue(json, "action");
  const char *capCmd = jsonValue(json, "captureCommandId");
  if ((capReq && !strncmp(capReq, "true", 4)) || (capAct && !strncmp(capAct, "\"capture\"", 9))) {
    bool isNew = true;
    if (capCmd && *capCmd == '"') {
      char id[sizeof(lastCaptureCommandId)] = "";
      const char *end = strchr(capCmd + 1, '"');
      size_t n = end ? (size_t)(end - capCmd - 1) : 0;
      if (n > 0 && n < sizeof(id)) {
        memcpy(id, capCmd + 1, n);
        id[n] = '\0';
        if (strcmp(id, lastCaptureCommandId) == 0) {
          isNew = false;
        } else {
          strcpy(lastCaptureCommandId, id);
        }
      }
    }
    if (isNew && !manualCaptureRequest) {
      manualCaptureRequest = true;
      Serial.println("[CMD] Manual capture foto dipicu!");
    }
  }

  if (const char *p = jsonValue(json, "lampMode")) {
    if      (!strncmp(p, "\"on\"", 4))   lampMode = LAMP_ON;
    else if (!strncmp(p, "\"off\"", 5))  lampMode = LAMP_OFF;
    else if (!strncmp(p, "\"auto\"", 6)) lampMode = LAMP_AUTO;
  }

  const char *req = jsonValue(json, "motorManualRequest");
  const char *cmd = jsonValue(json, "motorCommandId");
  if (req && !strncmp(req, "true", 4) && cmd && *cmd == '"') {
    char id[sizeof(lastMotorCommandId)] = "";
    const char *end = strchr(cmd + 1, '"');
    size_t n = end ? (size_t)(end - cmd - 1) : 0;
    if (n > 0 && n < sizeof(id)) {
      memcpy(id, cmd + 1, n);
      id[n] = '\0';
      if (strcmp(id, lastMotorCommandId) != 0) {
        Preferences prefs;
        if (prefs.begin("incubator", false)) {
          prefs.putString("lastMotorCmd", id);
          prefs.end();
        }
        strcpy(lastMotorCommandId, id);
        motorRequest = true;
      }
    }
  }

  float v;
  if (jsonNumber(json, "tempMin", v) && v >= 30 && v <= 45) tempSetpointMin = v;
  if (jsonNumber(json, "tempMax", v) && v >= 30 && v <= 45) tempSetpointMax = v;
  if (jsonNumber(json, "motorIntervalHours", v) && v >= 1 && v <= 24) motorIntervalMs = (uint32_t)v * 3600000UL;
  if (jsonNumber(json, "motorDurationSeconds", v) && v >= 1 && v <= 180) motorDurationMs = (uint32_t)v * 1000UL;
  if (jsonNumber(json, "captureIntervalSeconds", v)) {
    uint32_t s = constrain((uint32_t)v, UPLOAD_MIN_S, UPLOAD_MAX_S);
    uploadIntervalMs = s * 1000UL;
    if (s != storedUploadSeconds) {
      Preferences prefs;
      if (prefs.begin("incubator", false)) {
        prefs.putUInt("photoSeconds", s);
        prefs.end();
        storedUploadSeconds = s;
      }
    }
  }
}

// ─── CLOUD (Core 0, tidak pernah memblokir web lokal) ────────────────────────────
void cloudUpload() {
  uint32_t t0 = millis();
  if (!capturePhotoTo(cloudPhoto) || !cloudPhoto.buf || cloudPhoto.len == 0) {
    Serial.println("[UPLOAD] gagal ambil frame");
    return;
  }
  uint32_t captureMs = millis() - t0;

  if ((int32_t)(localWebBusyUntil - millis()) > 0) return;

  uint32_t t1 = millis();
  WiFiClient client;
  client.setNoDelay(true);
  client.setTimeout(6000);

  if (!client.connect("iot.walker880.my.id", 80)) {
    Serial.println("[UPLOAD] Gagal connect ke server");
    return;
  }

  // 1. Header HTTP non-blocking dengan Connection: close
  float t = currentTemp, h = currentHum;
  client.print("POST /api/incubator/upload HTTP/1.1\r\n");
  client.print("Host: iot.walker880.my.id\r\n");
  client.print("User-Agent: ESP32HTTPClient\r\n");
  client.print("X-Device-Key: " DEVICE_KEY "\r\n");
  client.print("Content-Type: image/jpeg\r\n");
  client.printf("Content-Length: %u\r\n", cloudPhoto.len);
  client.printf("X-RSSI: %d\r\n", WiFi.RSSI());
  if (isfinite(t) && t > 0) client.printf("X-Temp: %.1f\r\n", t);
  else                      client.print("X-Temp-Valid: 0\r\n");
  if (isfinite(h))          client.printf("X-Humidity: %.1f\r\n", h);
  else                      client.print("X-Humidity-Valid: 0\r\n");
  client.printf("X-Lamp: %d\r\n", heaterState ? 1 : 0);
  client.printf("X-Motor: %d\r\n", isMotorRunning ? 1 : 0);
  client.print("Connection: close\r\n\r\n");

  // 2. Kirim Buffer Foto dalam chunk 2048 byte tanpa macet di buffer lwIP
  size_t remaining = cloudPhoto.len;
  size_t offset = 0;
  while (remaining > 0 && client.connected()) {
    size_t toSend = (remaining > 2048) ? 2048 : remaining;
    size_t written = client.write(cloudPhoto.buf + offset, toSend);
    if (written > 0) {
      offset += written;
      remaining -= written;
    } else {
      delay(5);
    }
  }

  // 3. Baca Status & Response dengan cepat
  uint32_t waitResp = millis();
  while (client.connected() && !client.available() && millis() - waitResp < 4000) {
    delay(10);
  }

  int code = -1;
  String statusLine = client.readStringUntil('\n');
  if (statusLine.startsWith("HTTP/1.")) {
    code = statusLine.substring(9, 12).toInt();
  }

  // Lewati HTTP Header
  while (client.connected()) {
    String line = client.readStringUntil('\n');
    if (line == "\r" || line.length() == 0) break;
  }

  // Baca Response Body untuk perintah balik jika ada
  String body = "";
  while (client.available()) {
    body += (char)client.read();
  }
  client.stop();

  uint32_t sendMs = millis() - t1;

  if (code == 200 || code == 201) {
    applyCloudCommands(body);
  }

  lastUploadCode  = code;
  lastUploadTime  = millis();
  lastUploadBytes = cloudPhoto.len;
  Serial.printf("[UPLOAD] HTTP %d | %u KB | foto %lu ms | kirim %lu ms\n",
                code, (unsigned)(cloudPhoto.len / 1024), captureMs, sendMs);
}

// ─── MQTT PROTOKOL REALTIME (Cloudflare WSS Port 443) ─────────────────────────────
esp_mqtt_client_handle_t mqttClient = nullptr;
volatile bool mqttConnected = false;

static void mqttEventHandler(void *handler_args, esp_event_base_t base, int32_t event_id, void *event_data) {
  esp_mqtt_event_handle_t event = (esp_mqtt_event_handle_t)event_data;
  switch ((esp_mqtt_event_id_t)event_id) {
    case MQTT_EVENT_CONNECTED:
      mqttConnected = true;
      Serial.println("[MQTT] Terhubung ke Cloudflare WSS (Port 443)!");
      esp_mqtt_client_subscribe(mqttClient, MQTT_TOPIC_CONTROL, 1);
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
        applyCloudCommands(String(payload));
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
  mqtt_cfg.broker.address.hostname = "mqtt.walker880.my.id";
  mqtt_cfg.broker.address.port = 443;
  mqtt_cfg.broker.address.transport = MQTT_TRANSPORT_OVER_WSS;
  mqtt_cfg.broker.address.path = "/mqtt";
  mqtt_cfg.broker.verification.crt_bundle_attach = esp_crt_bundle_attach;
  // SNI wajib aktif (jangan skip common name) agar Cloudflare menerima TLS handshake
  mqtt_cfg.task.stack_size = 10240;
  mqtt_cfg.buffer.size = 512;
  mqtt_cfg.buffer.out_size = 512;
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

void publishTelemetry() {
  if (!mqttConnected || !mqttClient) return;
  float t = currentTemp, h = currentHum;
  char json[280];
  snprintf(json, sizeof(json),
    "{\"temp\":%s,\"hum\":%s,\"heater\":%s,\"lampMode\":\"%s\",\"motor\":%s,\"rssi\":%d}",
    isfinite(t) ? String(t, 1).c_str() : "null",
    isfinite(h) ? String((int)h).c_str() : "null",
    heaterPinOn ? "true" : "false",
    LAMP_MODE_NAME[lampMode],
    isMotorRunning ? "true" : "false",
    WiFi.RSSI()
  );
  esp_mqtt_client_publish(mqttClient, MQTT_TOPIC_TELEMETRY, json, 0, 0, 0);
}

void cloudPoll() {
  if (mqttConnected) return; // Prioritas MQTT: jangan polling HTTP jika MQTT terhubung
  WiFiClientSecure sclient;
  WiFiClient       pclient;
  HTTPClient       http;
  String url = String(SERVER_URL) + "/api/incubator/device-control";
  if (lastMotorCommandId[0]) url += String("?motorAck=") + lastMotorCommandId;

  if (useTls()) {
    sclient.setInsecure();
    sclient.setTimeout(5);
    http.begin(sclient, url);
  } else {
    http.begin(pclient, url);
  }

  http.setTimeout(4000);
  http.setUserAgent("ESP32HTTPClient");
  http.addHeader("X-Device-Key", DEVICE_KEY);
  if (http.GET() == 200) applyCloudCommands(http.getString());
  http.end();
}

void cloudTask(void *) {
  delay(2000); // Tunggu Core 1 dan WiFi stabil sebelum inisialisasi MQTT
  if (WiFi.status() == WL_CONNECTED) {
    initMqtt();
  }
  uint32_t lastPoll = millis();
  uint32_t lastTelemetry = millis();
  uint32_t lastUp = millis(); // Beri waktu MQTT handshake sebelum upload pertama

  for (;;) {
    uint32_t now = millis();
    bool localBusy = (int32_t)(localWebBusyUntil - now) > 0;

    if (!localBusy && WiFi.status() == WL_CONNECTED) {
      if (!mqttClient) {
        initMqtt();
      }

      // 1. Manual Capture seketika jika diminta via MQTT / Web
      if (manualCaptureRequest) {
        manualCaptureRequest = false;
        Serial.println("[UPLOAD] Menjalankan manual capture...");
        cloudUpload();
        lastUp = now;
      }
      // 2. Upload periodik otomatis (15s - 120s)
      else if (now - lastUp >= uploadIntervalMs) {
        lastUp = now;
        cloudUpload();
      }

      // 3. Telemetri hemat kuota ke MQTT tiap 3 detik
      if (now - lastTelemetry >= 3000) {
        lastTelemetry = now;
        publishTelemetry();
      }

      // 4. Fallback HTTP polling HANYA jika MQTT terputus
      if (!mqttConnected && (now - lastPoll >= 10000)) {
        lastPoll = now;
        cloudPoll();
      }
    }
    vTaskDelay(pdMS_TO_TICKS(100));
  }
}

// ─── WEB SERVER LOKAL (Core 1) ────────────────────────────────────────────────────
static int32_t secondsUntilTurn() {
  uint32_t passed = millis() - lastMotorTurn;
  return motorIntervalMs > passed ? (motorIntervalMs - passed) / 1000 : 0;
}

static int32_t secondsSinceUpload() {
  return lastUploadTime ? (millis() - lastUploadTime) / 1000 : -1;
}

void handleRoot() {
  localWebBusyUntil = millis() + 8000UL;
  float t = currentTemp, h = currentHum;
  char buf[640];
  snprintf(buf, sizeof(buf),
    "<div class=g>"
    "<div class=c><h3>Suhu</h3><b id=st>%s&deg;C</b><p>Setpoint %.1f - %.1f&deg;C</p></div>"
    "<div class=c><h3>Kelembaban</h3><b id=sh>%s%%</b><p>DHT22</p></div>"
    "<div class=c><h3>Aktuator</h3><p id=sa>Lampu: %s (%s)</p><p id=sm>Dinamo: %s</p><p>Putar lagi: %ld menit</p></div>"
    "<div class=c><h3>Upload Cloud</h3><b id=su>%d</b><p>Tiap %lu s | %ld s lalu | %lu KB</p></div>"
    "</div>",
    isfinite(t) ? String(t, 1).c_str() : "--", (float)tempSetpointMin, (float)tempSetpointMax,
    isfinite(h) ? String((int)h).c_str() : "--",
    heaterPinOn ? "MENYALA" : "MATI", LAMP_MODE_NAME[lampMode],
    isMotorRunning ? "BERPUTAR" : "STANDBY", (long)(secondsUntilTurn() / 60),
    lastUploadCode, (unsigned long)(uploadIntervalMs / 1000), (long)secondsSinceUpload(),
    (unsigned long)(lastUploadBytes / 1024));

  String html;
  html.reserve(2500);
  html += F("<!DOCTYPE html><html lang=id><head><meta charset=utf-8>"
            "<meta name=viewport content='width=device-width,initial-scale=1'><title>Inkubator</title><style>"
            "body{background:#0f172a;color:#f8fafc;font-family:system-ui,sans-serif;max-width:960px;margin:0 auto;padding:16px}"
            ".g{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0}"
            ".c{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:14px}"
            "h3{margin:0 0 6px;font-size:.8rem;color:#94a3b8;text-transform:uppercase}b{font-size:1.6rem}p{margin:4px 0;color:#cbd5e1}"
            "button{background:#0284c7;color:#fff;border:0;border-radius:8px;padding:12px 16px;margin:4px;font-weight:600;min-height:44px;cursor:pointer}"
            "button:disabled{opacity:.5;cursor:wait}"
            "button:focus-visible{outline:2px solid #38bdf8;outline-offset:2px}"
            "#info{color:#38bdf8;font-size:.9rem;margin:8px 4px}"
            "img{width:100%;border-radius:8px;background:#000;min-height:200px;margin-top:8px;display:block}"
            "</style></head><body><h1>Inkubator ESP32-CAM</h1><p>IP ");
  html += WiFi.localIP().toString();
  html += F(" | RSSI ");
  html += WiFi.RSSI();
  html += F(" dBm | UXGA 1600x1200</p>");
  html += buf;
  html += F("<button id=btn onclick=snap()>Ambil Foto (1600x1200)</button>"
            "<button onclick=\"if(confirm('Putar dinamo sekarang?'))fetch('/turn').then(r=>r.text()).then(alert)\">Putar Dinamo</button>"
            "<button onclick=location.reload()>Muat Ulang</button>"
            "<div id=info>Klik Ambil Foto untuk memotret resolusi penuh.</div>"
            "<img id=cam alt='Foto kamera inkubator'>"
            "<script>"
            "let t0=0;"
            "function snap(){"
            "btn.disabled=true;t0=performance.now();"
            "info.textContent='Memotret UXGA 1600x1200...';"
            "cam.src='/capture?t='+Date.now();"
            "}"
            "cam.onload=()=>{btn.disabled=false;info.textContent='Selesai dalam '+((performance.now()-t0)/1000).toFixed(2)+' detik ('+cam.naturalWidth+'x'+cam.naturalHeight+')'};"
            "cam.onerror=()=>{btn.disabled=false;info.textContent='Gagal memuat foto, coba lagi.'};"
            "setInterval(()=>fetch('/status').then(r=>r.json()).then(d=>{"
            "if(d.temperature!==null)st.innerHTML=d.temperature.toFixed(1)+'&deg;C';"
            "if(d.humidity!==null)sh.textContent=Math.round(d.humidity)+'%';"
            "sa.textContent='Lampu: '+(d.heater?'MENYALA':'MATI')+' ('+d.lamp_mode+')';"
            "sm.textContent='Dinamo: '+(d.motor_running?'BERPUTAR':'STANDBY');"
            "su.textContent=d.last_upload_code;"
            "}).catch(()=>{}),4000);"
            "</script></body></html>");
  server.send(200, "text/html", html);
}

void handleCapture() {
  localWebBusyUntil = millis() + 8000UL;
  uint32_t t0 = millis();
  if (!capturePhotoTo(localPhoto) || !localPhoto.buf || localPhoto.len == 0) {
    server.send(500, "text/plain", "Gagal mengambil frame");
    return;
  }
  uint32_t capMs = millis() - t0;

  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.sendHeader("Cache-Control", "no-store");
  server.setContentLength(localPhoto.len);
  server.send(200, "image/jpeg", "");
  server.setContentLength(CONTENT_LENGTH_NOT_SET);

  NetworkClient client = server.client();
  client.setNoDelay(true);
  client.setTimeout(10000);
  uint32_t t1 = millis();
  size_t sent = 0;
  while (sent < localPhoto.len && client.connected()) {
    size_t chunk = min((size_t)8192, localPhoto.len - sent);
    size_t n = client.write(localPhoto.buf + sent, chunk);
    if (n == 0) break;
    sent += n;
  }
  delay(20);
  uint32_t sendMs = millis() - t1;

  Serial.printf("[LOCAL CAP] %u/%u KB | foto %lu ms | kirim %lu ms\n",
                (unsigned)(sent / 1024), (unsigned)(localPhoto.len / 1024), capMs, sendMs);
}

void handleStatus() {
  localWebBusyUntil = millis() + 6000UL;  // selama halaman lokal dibuka, tunda upload cloud
  float t = currentTemp, h = currentHum;
  char json[480];
  snprintf(json, sizeof(json),
    "{\"temperature\":%s,\"humidity\":%s,\"heater\":%s,\"lamp_mode\":\"%s\","
    "\"motor_running\":%s,\"next_turn_sec\":%ld,\"auto_upload_interval_sec\":%lu,"
    "\"last_upload_code\":%d,\"last_upload_ago_sec\":%ld,\"rssi\":%d,\"ip\":\"%s\"}",
    isfinite(t) ? String(t, 2).c_str() : "null", isfinite(h) ? String(h, 1).c_str() : "null",
    heaterPinOn ? "true" : "false", LAMP_MODE_NAME[lampMode],
    isMotorRunning ? "true" : "false", (long)secondsUntilTurn(),
    (unsigned long)(uploadIntervalMs / 1000), lastUploadCode, (long)secondsSinceUpload(),
    WiFi.RSSI(), WiFi.localIP().toString().c_str());
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "application/json", json);
}

void handleTurn() {
  localWebBusyUntil = millis() + 6000UL;
  motorRequest = true;
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "text/plain", "Dinamo pembalik telur diputar");
}

void handleLed() {
  localWebBusyUntil = millis() + 6000UL;
  manualLight = server.hasArg("state") ? server.arg("state").toInt() > 0 : !manualLight;
  refreshHeaterPin();
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.send(200, "application/json", manualLight ? "{\"led\":true}" : "{\"led\":false}");
}

// ─── LCD 16x4 ─────────────────────────────────────────────────────────────────────
char lcdCache[4][17];

void lcdLine(uint8_t row, const char *fmt, ...) {
  char line[17];
  va_list ap;
  va_start(ap, fmt);
  int n = vsnprintf(line, sizeof(line), fmt, ap);
  va_end(ap);
  for (int i = constrain(n, 0, 16); i < 16; i++) line[i] = ' ';
  line[16] = '\0';
  if (memcmp(line, lcdCache[row], 16) == 0) return;
  memcpy(lcdCache[row], line, 17);
  lcd->setCursor(0, row);
  lcd->print(line);
}

void updateLcd() {
  if (!lcd) return;
  float t = currentTemp, h = currentHum;
  if (isfinite(t) && isfinite(h)) lcdLine(0, "T:%.1f%cC H:%d%%", t, 223, (int)h);
  else                            lcdLine(0, "Sensor DHT ERROR");
  lcdLine(1, "Lampu:%s [%s]", heaterPinOn ? "ON " : "OFF", LAMP_MODE_NAME[lampMode]);
  if (isMotorRunning) lcdLine(2, "Dinamo: PUTAR");
  else {
    long m = secondsUntilTurn() / 60;
    lcdLine(2, "Putar: %ldj %02ldm", m / 60, m % 60);
  }
  if (lastUploadCode) lcdLine(3, "Up:%d IP:.%d", lastUploadCode, WiFi.localIP()[3]);
  else                lcdLine(3, "%s", WiFi.localIP().toString().c_str());
}

void initLcd() {
  Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);
  Wire.setTimeOut(50);
  for (uint8_t addr : {0x27, 0x3F}) {
    Wire.beginTransmission(addr);
    if (Wire.endTransmission() == 0) {
      lcd = new LiquidCrystal_I2C(addr, 16, 4);
      lcd->init();
      lcd->backlight();
      lcdLine(0, "INKUBATOR TELUR");
      lcdLine(1, "Menyiapkan...");
      return;
    }
  }
  Serial.println("[LCD] tidak terdeteksi");
}

// ─── SETUP & LOOP ─────────────────────────────────────────────────────────────────
void setup() {
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0);
  esp_log_level_set("gpio", ESP_LOG_NONE);

  pinMode(PIN_RELAY_HEATER, OUTPUT);
  pinMode(PIN_RELAY_MOTOR, OUTPUT);
  digitalWrite(PIN_RELAY_HEATER, RELAY_OFF);
  digitalWrite(PIN_RELAY_MOTOR, RELAY_OFF);

  Serial.begin(115200);
  Serial.println("\n[BOOT] Inkubator ESP32-CAM (UXGA 1600x1200)");
  cameraMutex = xSemaphoreCreateMutex();

  Preferences prefs;
  if (prefs.begin("incubator", true)) {
    prefs.getString("lastMotorCmd", lastMotorCommandId, sizeof(lastMotorCommandId));
    storedUploadSeconds = constrain(prefs.getUInt("photoSeconds", 120), UPLOAD_MIN_S, UPLOAD_MAX_S);
    uploadIntervalMs = storedUploadSeconds * 1000UL;
    prefs.end();
  }

  dht.begin();
  initLcd();

  if (camStart()) {
    Serial.println("[CAM] siap (UXGA, AWB aktif, DMA idle saat tidak memotret)");
  } else {
    Serial.println("[CAM] GAGAL inisialisasi");
  }

  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  WiFi.setTxPower(WIFI_POWER_19_5dBm);
  esp_wifi_set_ps(WIFI_PS_NONE);  // wajib setelah WiFi.begin() agar modem tidak tidur saat idle

  for (int i = 0; i < 30 && WiFi.status() != WL_CONNECTED; i++) delay(400);
  esp_wifi_set_ps(WIFI_PS_NONE);
  Serial.printf("[WIFI] %s %s (RSSI %d dBm)\n",
                WiFi.status() == WL_CONNECTED ? "terhubung" : "offline",
                WiFi.localIP().toString().c_str(), WiFi.RSSI());

  if (WiFi.status() == WL_CONNECTED) {
    configTime(7 * 3600, 0, "pool.ntp.org", "time.google.com");
  }

  server.on("/", HTTP_GET, handleRoot);
  server.on("/capture", HTTP_GET, handleCapture);
  server.on("/status", HTTP_GET, handleStatus);
  server.on("/turn", HTTP_GET, handleTurn);
  server.on("/led", HTTP_GET, handleLed);
  server.on("/favicon.ico", HTTP_GET, []() { server.send(204); });
  server.begin();

  lastMotorTurn = millis();
  Serial.printf("[INIT] Free Heap: %u, Free PSRAM: %u\n", ESP.getFreeHeap(), ESP.getFreePsram());
  xTaskCreatePinnedToCore(cloudTask, "cloud", 16384, NULL, 1, NULL, 0);
}

void loop() {
  static uint32_t lastSensor = 0, lastLcd = 0;
  static uint8_t dhtFails = 0;

  server.handleClient();
  uint32_t now = millis();

  if (now - lastSensor >= 2000) {
    lastSensor = now;
    float t = dht.readTemperature();
    float h = dht.readHumidity();
    if (isfinite(t) && isfinite(h)) {
      currentTemp = t;
      currentHum = h;
      dhtFails = 0;
    } else if (++dhtFails >= DHT_MAX_FAILS) {
      currentTemp = NAN;
      currentHum = NAN;
    }
    updateThermostat();
  }

  refreshHeaterPin();
  updateMotor(now);

  if (now - lastLcd >= 1000) {
    lastLcd = now;
    updateLcd();
  }
}
