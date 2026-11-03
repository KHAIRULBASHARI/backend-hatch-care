# Sistem Cerdas Monitoring Penetasan Telur Ayam & Candling Berbasis ESP32-CAM

Proyek ini merupakan sistem monitoring otomatis untuk inkubator penetasan telur ayam dan unit candling menggunakan 2 modul **ESP32-CAM AI-Thinker** dengan bodi **Triplek Tipis** dan mekanisme **Rak Gelinding (Roller Egg Turner)**.

---

## 🏛️ Arsitektur Desain Fisik 3D (Tersedia di Blender `.blend`)

File 3D lengkap dapat dibuka langsung di Blender:  
📁 `D:\project egg\desain_3d_penetasan_dan_candling.blend`

### 1. Unit 1: Mesin Penetasan (Incubator)
- **Material Bodi:** Kayu Triplek tipis (*Thin Plywood*) dengan tekstur serat kayu alami dan pintu depan kaca akrilik transparan.
- **Mekanisme Rak Telur:** **Rak Gelinding (Roller System)**:
  - 7 pipa silinder roller berputar horizontal berjejer.
  - Telur-telur diletakkan horizontal di antara celah 2 pipa roller.
  - Roda gigi (*gears*) di sisi samping terhubung ke batang rel penggerak.
  - Digerakkan oleh **Dinamo Motor Asinkron AC 220V (2.5/3 RPM)** dengan tuas engkol sehingga telur menggelinding berputar perlahan otomatis tiap beberapa jam.
- **Nampan Air (Water Tray):** Terletak di lantai dasar di bawah rak gelinding untuk menjaga kelembaban.
- **Kamera Overhead ESP32-CAM:** Terpasang di plafon atap tepat di tengah interior, lensa mengarah vertikal lurus ke bawah memantau seluruh rak telur.
- **Pemanas (2x Lampu Pijar AC 220V):** Fitting keramik terpasang di langit-langit kiri dan kanan.
- **Kipas Sirkulasi:** Di dinding belakang meniupkan sirkulasi udara hangat merata ke seluruh ruangan.
- **Sensor DHT22:** Terpasang di dinding samping tepat sejajar ketinggian telur.
- **Panel Kontrol Luar:** Layar **LCD 16x4 I2C** dan **Modul Relay 3-Channel**.

### 2. Unit 2: Unit Candling (Ruang Gelap Tertutup / Dark Chamber)
- **Bodi Ruang Tertutup:** Kotak triplek dengan **interior hitam doff kedap cahaya (*Light-Tight Chamber*)** agar cahaya luar tidak bocor dan tidak mengganggu kontras citra telur.
- **Pintu Akses Telur:** Pintu flap/engsel kedap cahaya di bagian depan untuk memasukkan telur dan ditutup saat pemotretan.
- **Corong Karet & Lampu Sorot:** Corong karet fleksibel di meja dalam yang menahan telur, dengan **Lampu Sorot LED HPL 3W-5W** di bawahnya yang menembus telur ke atas.
- **Kamera Makro ESP32-CAM:** Terpasang di plafon dalam ruang gelap menghadap telur dengan jarak fokus dekat (~10-15 cm).
- **Panel Operator Luar:** Tombol shutter fisik hijau, buzzer beep, dan saklar daya dipasang di dinding luar agar operator dapat memicu foto dari luar tanpa membuka pintu gelap.

---

## 🔌 Panduan Wiring Lengkap

### Unit 1: ESP32-CAM Penetasan (Inkubator)

#### 1. Sinyal Tegangan Rendah (DC 5V & 3.3V)
| Pin ESP32-CAM | Terhubung ke | Keterangan |
| :--- | :--- | :--- |
| **5V** | Power Supply DC +5V (min 2A) | Sumber daya utama modul |
| **GND** | Ground Bersama (Common Ground) | Disatukan ke semua GND modul |
| **3.3V** | Pin VCC Sensor DHT22 | Daya referensi sensor |
| **GPIO 16** | Pin DATA Sensor DHT22 | Jalur baca suhu & kelembaban |
| **GPIO 13** | Pin SDA LCD I2C (PCF8574) | Jalur Data I2C |
| **GPIO 14** | Pin SCL LCD I2C (PCF8574) | Jalur Clock I2C |
| **GPIO 15** | Pin IN1 Modul Relay | Kontrol Lampu Pemanas |
| **GPIO 2** | Pin IN2 Modul Relay | Kontrol Dinamo Pembalik Telur |
| **GPIO 12** | Pin IN3 Modul Relay | Kontrol Kipas Sirkulasi AC 220V |

#### 2. Sambungan Beban AC 220V ke Relay (Bebas Resistor)
- **Kabel Fasa / Live (L) PLN 220V** $\rightarrow$ Masuk ke terminal **`COM`** Relay 1, dijumper ke **`COM`** Relay 2, dan dijumper ke **`COM`** Relay 3.
- **Kabel Netral (N) PLN 220V** $\rightarrow$ Langsung menuju ke kaki kedua dari semua beban (Lampu, Dinamo, Kipas).
- **Terminal NO (Normally Open) Relay:**
  - **`NO` Relay 1** $\rightarrow$ Langsung ke Fitting Lampu Pemanas 220V.
  - **`NO` Relay 2** $\rightarrow$ Langsung ke Dinamo Motor Pembalik Telur 220V.
  - **`NO` Relay 3** $\rightarrow$ Langsung ke Kipas Sirkulasi AC 220V.

---

### Unit 2: ESP32-CAM Candling (Peneropongan Telur)

| Pin ESP32-CAM | Terhubung ke | Keterangan |
| :--- | :--- | :--- |
| **5V & GND** | Adaptor 5V DC (min 2A) | Sumber daya |
| **GPIO 4** | Internal Board | Mengendalikan Flash LED putih terang bawaan |
| **GPIO 13** | Push Button (Kaki 1) | Tombol shutter fisik (Kaki 2 ke GND, `INPUT_PULLUP`) |
| **GPIO 2** | Active Buzzer (+) | Indikator bunyi beep foto selesai (Kaki `-` ke GND) |
| **GPIO 16** | Modul Relay (Pin IN) | Trigger lampu sorot candling eksternal (High Power LED) |

---

## 💻 File Simulasi Wokwi & Desain 3D
- Simulasi Wokwi Penetasan: `esp32_incubator/diagram.json`
- Simulasi Wokwi Candling: `esp32_candling/diagram.json`
- File 3D Blender: `desain_3d_penetasan_dan_candling.blend`

---

## 🚀 Fitur Capture Telur Jarak Jauh (Laravel + ESP32-CAM)

Fitur ini memungkinkan pengambilan citra telur (candling) secara jarak jauh:
Dashboard Laravel membuat job pemotretan, ESP32-CAM AI-Thinker melakukan polling, menyalakan relay LED candling (GPIO14), mengambil foto, dan mengirimkan hasil JPEG ke server.

### 1. Struktur Folder
- `backend/` : Aplikasi Laravel (API Device, Controller Candling, Dashboard UI, Database Migrations, Scheduled Jobs).
- `firmware/capture-telur/` : Source code firmware Arduino untuk board AI-Thinker ESP32-CAM (`capture-telur.ino` & `secrets.h`).

### 2. Setup Server Laravel (`backend/`)
1. Masuk ke direktori backend:
   ```bash
   cd backend
   ```
2. Salin environment dan generate key:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
3. Konfigurasi variabel `.env`:
   ```env
   APP_URL=https://domainkamu.com
   DEVICE_KEY=isi-string-acak-minimal-32-karakter
   DB_CONNECTION=sqlite
   CACHE_STORE=database
   ```
4. Jalankan migrasi database dan buat symbolic link storage:
   ```bash
   php artisan migrate
   php artisan storage:link
   ```
5. Konfigurasi Web Server (Nginx) — Wajib untuk upload JPEG hingga 5MB:
   ```nginx
   client_max_body_size 5M;
   ```
6. Jalankan scheduler Laravel (cron) untuk membersihkan file & job lebih dari 30 hari:
   ```bash
   php artisan schedule:run
   ```

### 3. Setup & Flash Firmware ESP32-CAM (`firmware/capture-telur/`)
1. Salin template kredensial:
   ```bash
   cp firmware/capture-telur/secrets.h.example firmware/capture-telur/secrets.h
   ```
2. Isi nilai sebenarnya di `firmware/capture-telur/secrets.h`:
   - `WIFI_SSID` : Nama WiFi router (contoh: "Telur").
   - `WIFI_PASS` : Password WiFi router (contoh: "12345678").
   - `SERVER_URL` : URL server tanpa trailing slash (contoh: "https://domainkamu.com").
   - `DEVICE_KEY` : String yang sama persis dengan `DEVICE_KEY` di `.env` Laravel.
3. Panduan Flashing AI-Thinker ESP32-CAM:
   - Hubungkan pin **GPIO0 ke GND** sebelum menyalakan/memasukkan kabel USB-UART (masuk mode bootloader).
   - Tekan tombol **RST**.
   - Compile dan flash:
     ```bash
     arduino-cli compile --fqbn esp32:esp32:esp32cam firmware/capture-telur
     arduino-cli upload -p <PORT_COM> --fqbn esp32:esp32:esp32cam firmware/capture-telur
     ```
   - Lepas kabel jumper **GPIO0 dari GND**, lalu tekan tombol **RST** sekali lagi agar firmware boot normal.

### 4. Pengujian Manual via cURL
1. Buat Job Pengambilan Foto:
   ```bash
   curl -X POST https://domainkamu.com/egg/capture \
     -H "Content-Type: application/json" \
     -d '{"exposure":500,"gain":0,"quality":6,"settle_ms":250}'
   # Response: {"job": 1}
   ```
2. Simulasi Polling oleh ESP32:
   ```bash
   curl https://domainkamu.com/api/device/poll \
     -H "X-Device-Key: <DEVICE_KEY>" \
     -H "X-RSSI: -50"
   # Response: job=1;exp=500;gain=0;q=6;delay=250
   ```
3. Simulasi Upload Foto JPEG Mentah oleh ESP32:
   ```bash
   curl -X POST "https://domainkamu.com/api/device/upload?job=1" \
     -H "X-Device-Key: <DEVICE_KEY>" \
     -H "Content-Type: image/jpeg" \
     --data-binary "@foto_telur.jpg"
   # Response: OK
   ```
4. Cek Status Job & Riwayat:
   ```bash
   curl https://domainkamu.com/egg/job/1
   curl https://domainkamu.com/egg/history
   curl https://domainkamu.com/egg/status
   ```

### 5. Troubleshooting Cepat
- **HTTP 401 Unauthorized**: Header `X-Device-Key` tidak disertakan atau nilainya tidak cocok dengan `DEVICE_KEY` di `.env`.
- **HTTP 409 Conflict**: Job yang di-upload belum dalam status `taken` atau sudah selesai (`done`/`failed`).
- **HTTP 413 Payload Too Large**: Ukuran gambar melebihi batas Nginx. Pastikan `client_max_body_size 5M;` sudah ditambahkan pada block server Nginx.
- **Kamera Gagal Inisialisasi**:
  - Pastikan modul kamera terpasang rapat pada socket FPC.
  - Periksa kecukupan arus power supply (ESP32-CAM butuh minimal 5V 2A stabil).
  - Pastikan opsi PSRAM diaktifkan pada build configuration.
- **Foto Terbalik / Rotasi Salah**:
  - Sensor OV3660 memiliki default orientation berbeda. Pada fungsi `tuneSensor()`, sesuaikan pemanggilan `s->set_vflip(s, 1)` atau `s->set_hmirror(s, 1)`.
