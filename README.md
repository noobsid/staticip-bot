# StaticIP Bot

Bot Telegram untuk manajemen pelanggan **IP statik / PPPoE** di MikroTik: isolir otomatis saat jatuh tempo, restore limit saat pembayaran, semua dari dalam chat Telegram.

Dibuat untuk jalan di **Termux (Android)** — ringan, tanpa web server, tanpa database server (SQLite), tanpa scheduler MikroTik.

<p align="left">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white">
  <img alt="SQLite" src="https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white">
  <img alt="Telegram Bot API" src="https://img.shields.io/badge/Telegram-Bot%20API-26A5E4?logo=telegram&logoColor=white">
  <img alt="MikroTik" src="https://img.shields.io/badge/MikroTik-RouterOS%20API-293239">
  <img alt="Platform" src="https://img.shields.io/badge/platform-Termux%20%7C%20Linux%20%7C%20Windows-2CA5E0">
</p>

---

## Daftar Isi

- [Fitur](#fitur)
- [Cara Kerja](#cara-kerja)
- [Persyaratan](#persyaratan)
- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Persiapan MikroTik](#persiapan-mikrotik)
- [Persiapan Telegram Bot](#persiapan-telegram-bot)
- [Menjalankan Bot](#menjalankan-bot)
- [Panduan Pemakaian](#panduan-pemakaian)
- [Struktur Proyek](#struktur-proyek)
- [Skema Database](#skema-database)
- [Desain UI Anti-Spam](#desain-ui-anti-spam)
- [Menjalankan Otomatis (Termux)](#menjalankan-otomatis-termux)
- [Troubleshooting](#troubleshooting)
- [Keamanan](#keamanan)
- [FAQ](#faq)
- [Lisensi](#lisensi)

---

## Fitur

| Fitur | Keterangan |
|---|---|
| 📋 **List & detail pelanggan** | Daftar user dengan indikator status 🟢 aktif / 🔴 isolir, paginasi 8 per halaman |
| ➕ **Tambah user** | Form berbasis tombol, draft tampil live, validasi IP & tanggal |
| ✏️ **Edit per field** | Ubah nama, IP, tgl bayar, max up, max down, catatan tanpa hapus data |
| 🔒 **Isolir / unisolir manual** | Satu ketukan, langsung mengubah `max-limit` simple queue di MikroTik |
| ⏫ **Perpanjang otomatis** | Set tgl bayar baru → hitung tgl isolir → restore limit normal sekaligus |
| 🔔 **Toggle notifikasi per user** | Notifikasi isolir bisa dinyalakan/dimatikan per pelanggan |
| ⏰ **Isolir otomatis** | Cek tiap 60 detik; user yang lewat tgl isolir otomatis diturunkan ke `ISOLIR_LIMIT` |
| 📊 **Ringkasan** | Total user, aktif, isolir, dan yang jatuh tempo ≤ 7 hari |
| 🧹 **UI satu pesan** | Seluruh navigasi mengedit **satu pesan** — chat tidak pernah menumpuk |
| 💾 **Tanpa database server** | SQLite single file, mudah dibackup dan dipindah |
| 🛡️ **Single-admin lock** | Hanya `ADMIN_TG_ID` yang bisa memakai bot |

---

## Cara Kerja

```
┌──────────────┐   long polling    ┌──────────────┐
│   Telegram   │ ◄──────────────── │              │
│  (admin HP)  │ ────────────────► │   bot.php    │
└──────────────┘    tombol/teks    │              │
                                   │  ┌────────┐  │
┌──────────────┐   RouterOS API    │  │ SQLite │  │
│   MikroTik   │ ◄──────────────── │  │ app.db │  │
│ port 8728    │                   │  └────────┘  │
└──────────────┘                   └──────────────┘
```

1. Bot memakai **long polling** (`getUpdates`) — tidak butuh IP publik, webhook, atau port forwarding. Cocok untuk HP di belakang NAT.
2. Setiap 60 detik bot memeriksa user yang `tgl_isolir <= hari ini` lalu menurunkan limit queue-nya ke `ISOLIR_LIMIT`.
3. Isolir/restore dilakukan dengan **mencari simple queue berdasarkan IP pada field `target`** (bukan nama queue), lalu mengubah `max-limit`.
4. Semua state percakapan disimpan di SQLite (`bot_state`, `bot_ui`, `bot_temp`), jadi bot aman di-restart kapan saja.

---

## Persyaratan

- **PHP 8.1+** dengan ekstensi `pdo_sqlite` (wajib) dan `curl` (opsional, disarankan)
- **MikroTik RouterOS** dengan service `api` aktif (port 8728)
- **Bot Telegram** (token dari [@BotFather](https://t.me/BotFather))
- Konektivitas dari perangkat yang menjalankan bot ke router MikroTik (LAN/VPN/WireGuard)

Cek ekstensi:

```bash
php -m | grep -E 'pdo_sqlite|curl'
```

---

## Instalasi

### Termux (Android)

```bash
# 1. Update & install paket
pkg update -y && pkg upgrade -y
pkg install -y php php-curl

# 2. Izinkan akses storage (untuk ekstrak zip dari folder Download)
termux-setup-storage

# 3. Ekstrak project
cd ~
unzip -o ~/storage/downloads/staticip-bot.zip
cd staticip-bot

# 4. Jalankan
php bot.php
```

> Saran: taruh project di storage internal (`~/staticip-bot`), bukan di SD card. Lebih cepat dan tidak kena batasan permission.

### Linux / VPS

```bash
sudo apt install -y php-cli php-sqlite3 php-curl unzip
unzip staticip-bot.zip -d ~/staticip-bot
cd ~/staticip-bot
php bot.php
```

### Windows (XAMPP)

```powershell
# Pastikan php.exe ada di PATH, atau panggil lengkap:
C:\xampp3\php\php.exe bot.php
```

---

## Konfigurasi

Edit `config.php`:

```php
<?php
// --- Database (SQLite) ---
define('DB_FILE', __DIR__ . '/data/app.db');

// --- MikroTik (RouterOS API port 8728) ---
define('MK_HOST', '<IP-ROUTER>');     // IP router MikroTik
define('MK_PORT', 8728);              // port API
define('MK_USER', '<USER-MIKROTIK>'); // user MikroTik (policy api,read,write,test)
define('MK_PASS', '<PASSWORD>');      // password MikroTik

// --- Aturan isolir ---
define('ISOLIR_DAYS', 35);           // tgl isolir = tgl bayar + N hari
define('ISOLIR_LIMIT', '64k/64k');   // limit saat isolir (up/down)

// --- Telegram ---
define('TG_BOT_TOKEN', '<TOKEN-DARI-BOTFATHER>'); // token dari @BotFather
define('TG_CHAT_ID', '<CHAT-ID-ADMIN>');          // tujuan notifikasi
define('ADMIN_TG_ID', '<CHAT-ID-ADMIN>');         // HANYA ID ini yang boleh pakai bot

date_default_timezone_set('Asia/Jakarta');
```

| Konstanta | Wajib | Keterangan |
|---|---|---|
| `DB_FILE` | ✅ | Lokasi file SQLite. Folder `data/` dibuat otomatis |
| `MK_HOST` / `MK_PORT` | ✅ | Alamat & port RouterOS API |
| `MK_USER` / `MK_PASS` | ✅ | Kredensial user MikroTik (butuh policy `api`, `read`, `write`) |
| `ISOLIR_DAYS` | ✅ | Umur langganan dalam hari, dihitung dari tgl bayar |
| `ISOLIR_LIMIT` | ✅ | Limit saat isolir, format `upload/download` (mis. `64k/64k`, `1M/1M`) |
| `TG_BOT_TOKEN` | ✅ | Token bot Telegram |
| `TG_CHAT_ID` | ✅ | Chat penerima notifikasi isolir (boleh sama dengan admin) |
| `ADMIN_TG_ID` | ✅ | **Satu-satunya** Telegram ID yang diizinkan memakai bot |

### Cara mengetahui Telegram ID

Kirim pesan ke [@userinfobot](https://t.me/userinfobot) atau [@RawDataBot](https://t.me/RawDataBot), lalu salin angka `id`.

---

## Persiapan MikroTik

### 1. Aktifkan service API

```routeros
/ip service enable api
/ip service set api port=8728
```

Batasi akses dari IP server bot saja:

```routeros
/ip service set api address=<SUBNET-SERVER-BOT>/24
```

### 2. Buat user khusus (least privilege)

```routeros
/user group add name=bot policy=api,read,write,test,winbox
/user add name=<USER-BOT> group=bot password=<PASSWORD-KUAT>
```

> Hindari memakai user `admin`/`full` untuk bot. Cukup policy `api,read,write,test`.

### 3. Pastikan simple queue punya target IP

Bot mencari queue berdasarkan **IP pada field `target`**, bukan nama queue. Jadi setiap pelanggan IP statik harus punya simple queue:

```routeros
/queue simple add name="pelanggan-budi" target=<IP-PELANGGAN>/32 max-limit=5M/5M
```

Cek daftar queue:

```routeros
/queue simple print where target~"<prefix-IP-pelanggan>"
```

### 4. Tidak perlu scheduler

Versi bot ini **tidak memerlukan** `/system scheduler` di MikroTik. Isolir otomatis dijalankan oleh bot sendiri tiap 60 detik.

---

## Persiapan Telegram Bot

1. Chat [@BotFather](https://t.me/BotFather) → `/newbot`
2. Isi nama & username bot
3. Salin token (`123456789:AA...`) ke `TG_BOT_TOKEN`
4. Buka chat bot, tekan **Start** (wajib, agar bot bisa mengirim pesan ke kamu)
5. Salin ID kamu ke `ADMIN_TG_ID` dan `TG_CHAT_ID`

---

## Menjalankan Bot

```bash
php bot.php
```

Output normal:

```
Bot jalan. Tekan Ctrl+C untuk berhenti.
[2026-10-20 22:41:03] ISOLIR: Budi | NOTIF: 1 user
```

Bot akan mengirim menu utama ke chat admin. Hentikan dengan `Ctrl+C`.

---

## Panduan Pemakaian

### Menu utama

```
👋 STATIC-IP BOT
Pilih menu di bawah.

[📋 List User] [➕ Tambah User]
[📊 Ringkasan]
```

### Tambah user

Ketuk **➕ Tambah User** → form draft muncul:

```
➕ TAMBAH USER BARU
Isolir otomatis: 2026-11-24

Nama: Budi
IP: <IP-PELANGGAN>
Tgl Bayar: 2026-10-20
Max Up: 5M
Max Down: 5M
Catatan: —

Ketuk 💾 Simpan untuk menyimpan.

[Nama] [IP] [Tgl Bayar]
[Max Up] [Max Down] [Catatan]
[💾 Simpan]
[❌ Batal]
```

- Ketuk tombol field → kirim nilainya → draft langsung ter-update
- **💾 Simpan** baru muncul setelah **Nama** dan **IP** valid
- Tgl isolir dihitung otomatis (`tgl_bayar + ISOLIR_DAYS`)

Format input:

| Field | Format | Contoh |
|---|---|---|
| Nama | teks bebas | `Budi Santoso` |
| IP | IPv4 valid, unik | `<IP-PELANGGAN>` |
| Tgl Bayar | `YYYY-MM-DD` atau `today` | `2026-10-20` |
| Max Up / Down | format RouterOS | `5M`, `1500k`, `1G` |
| Catatan | teks atau `-` untuk kosong | `paket 10Mbps` |

### Detail & aksi user

```
👤 Budi Santoso
IP: <IP-PELANGGAN>
Status: 🟢 AKTIF
Tgl Bayar: 2026-10-20
Tgl Isolir: 2026-11-24
Limit normal: 5M/5M
Notif: ON
Catatan: paket 10Mbps

[🔒 Isolir]
[⏫ Perpanjang] [📅 Edit Isolir]
[🔔 Notif: ON] [✏️ Edit]
[🗑 Hapus] [⬅️ Kembali]
```

| Tombol | Fungsi |
|---|---|
| 🔒 Isolir / 🔓 Unisolir | Ubah `max-limit` queue ke `ISOLIR_LIMIT` / kembali normal |
| ⏫ Perpanjang | Input tgl bayar baru → hitung tgl isolir → restore limit |
| 📅 Edit Isolir | Ubah tanggal isolir saja tanpa menyentuh limit |
| 🔔 Notif | Toggle notifikasi isolir untuk pelanggan ini |
| ✏️ Edit | Pilih field yang mau diubah |
| 🗑 Hapus | Hapus data user (dengan konfirmasi) |

### Membatalkan input

Kapan saja saat diminta mengirim nilai, kirim teks `batal` atau ketuk **⬅️ Batal**.

---

## Struktur Proyek

```
staticip-bot/
├── bot.php          # Entry point + long polling loop + timer isolir otomatis
├── core.php         # State percakapan, penyimpanan pointer pesan UI, render
├── ui.php           # Semua tampilan (menu, list, detail, form tambah/edit)
├── actions.php      # Aksi bisnis: isolir, restore, simpan, hapus, edit
├── handlers.php     # Routing callback tombol & input teks
├── functions.php    # Helper: MikroTik queue, Telegram API, hitung tanggal
├── RouterosAPI.php  # Client RouterOS API port 8728 (login legacy + challenge)
├── db.php           # Koneksi PDO SQLite + skema tabel
├── config.php       # Konfigurasi (token, kredensial, aturan isolir)
└── data/
    └── app.db       # Database SQLite (dibuat otomatis)
```

| File | Tanggung jawab |
|---|---|
| `bot.php` | Hanya bootstrap + loop. Tidak ada logika UI |
| `core.php` | Infrastruktur UI: `set_state`, `store_*`, `render`, `ui`, `notify_ui`, `temp_purge` |
| `ui.php` | Fungsi murni tampilan: `view_menu`, `view_list`, `view_user`, `view_add`, dll |
| `actions.php` | Efek samping: query DB + perintah MikroTik, mengembalikan string status |
| `handlers.php` | Menerjemahkan update Telegram → panggilan view/action |

Pemisahan ini membuat tampilan bisa diuji tanpa jaringan (lihat bagian [Desain UI Anti-Spam](#desain-ui-anti-spam)).

---

## Skema Database

### `users`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INTEGER PK | Auto increment |
| `nama` | TEXT | Nama pelanggan |
| `ip` | TEXT **UNIQUE** | IP statik pelanggan |
| `tgl_bayar` | TEXT | Tanggal pembayaran terakhir (`YYYY-MM-DD`) |
| `tgl_isolir` | TEXT | Tanggal mulai isolir (`tgl_bayar + ISOLIR_DAYS`) |
| `max_upload` | TEXT | Limit normal upload (mis. `5M`) |
| `max_download` | TEXT | Limit normal download (mis. `5M`) |
| `catatan` | TEXT | Catatan bebas |
| `status` | TEXT | `aktif` atau `isolir` |
| `notif_enabled` | INTEGER | `1` = kirim notifikasi, `0` = diam |
| `last_notif_sent` | TEXT | Tanggal terakhir notifikasi dikirim (anti dobel per hari) |
| `created_at` | TEXT | Waktu dibuat |

### `bot_state`
State percakapan input bertahap per chat (`chat_id`, `state`, `data`).

### `bot_ui`
Pointer ke **satu pesan utama** yang selalu di-edit (`chat_id`, `message_id`, `view`, `data`).

### `bot_temp`
Daftar pesan sementara yang harus dihapus (`id`, `chat_id`, `message_id`, `created_at`).

---

## Desain UI Anti-Spam

Masalah umum bot Telegram: setiap aksi mengirim pesan baru, sehingga menu dan tombol tertimbun dan sulit dipakai lagi.

Bot ini memakai prinsip **single-message UI**:

| Perilaku lama | Perilaku bot ini |
|---|---|
| Setiap aksi `sendMessage` baru | `editMessageText` pada pesan yang sama |
| Prompt "Kirim nama..." = pesan baru | Prompt **menimpa** isi pesan utama |
| Notifikasi isolir = 1 pesan per user | **1 pesan notifikasi** yang di-edit ulang |
| Pesan teks admin menumpuk | **Dihapus otomatis** via `deleteMessage` |
| Hasil aksi jadi pesan terpisah | Baris status inline di pesan utama |

Implementasi:

- `bot_ui` menyimpan `message_id` pesan utama per chat → `render()` selalu mencoba `editMessageText` dulu
- Jika pesan lama sudah tidak bisa diedit (dihapus manual / terlalu lama), bot otomatis kirim ulang dan menyimpan id baru
- `message_id` pesan admin yang masuk dicatat lalu dihapus setelah diproses
- Notifikasi isolir diagregasi jadi satu pesan, bukan satu pesan per pelanggan

Hasil pengujian: seluruh alur **menu → list → ringkasan → form → isi 2 field → simpan** hanya memakai **1 pesan**.

---

## Menjalankan Otomatis (Termux)

Agar bot tetap hidup setelah Termux ditutup:

### 1. Matikan optimasi baterai

`Android Settings → Apps → Termux → Battery → Unrestricted`

### 2. Aktifkan wakelock

```bash
termux-wake-lock
```

### 3. Autostart dengan `~/.bashrc`

```bash
echo 'cd ~/staticip-bot && php bot.php' >> ~/.bashrc
```

### 4. Auto-restart bila crash (loop)

```bash
cat > ~/run-bot.sh <<'EOF'
#!/data/data/com.termux/files/usr/bin/bash
termux-wake-lock
cd ~/staticip-bot
while true; do
  php bot.php
  echo "Bot berhenti, restart 10 detik..."
  sleep 10
done
EOF
chmod +x ~/run-bot.sh

echo '~/run-bot.sh' >> ~/.bashrc
```

### 5. Alternatif: systemd (VPS Linux)

```ini
# /etc/systemd/system/staticip-bot.service
[Unit]
Description=StaticIP Telegram Bot
After=network-online.target

[Service]
Type=simple
WorkingDirectory=/root/staticip-bot
ExecStart=/usr/bin/php /root/staticip-bot/bot.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now staticip-bot
journalctl -u staticip-bot -f
```

---

## Troubleshooting

| Gejala | Penyebab | Solusi |
|---|---|---|
| `TG_BOT_TOKEN kosong` | Token belum diisi | Isi `config.php`, jalankan ulang |
| Bot diam, tidak ada respon | Belum tekan **Start** di chat bot | Buka bot, tekan Start |
| `getUpdates error: 409 Conflict` | Bot yang sama jalan di 2 tempat | Hentikan instance lain (cek Termux + XAMPP) |
| `Koneksi MikroTik gagal` | Service `api` mati / IP salah / firewall | `/ip service enable api`, cek `MK_HOST` |
| `Login gagal` / `!trap` | User tidak punya policy `api`/`write` | Buat user dengan group `bot` (lihat [Persiapan MikroTik](#persiapan-mikrotik)) |
| `Queue untuk IP ... tidak ditemukan` | Simple queue tidak ada / target beda | Buat queue dengan `target=<IP>/32` |
| Limit tidak berubah | Queue dicocokkan via nama, bukan target | Pastikan `target=` berisi IP pelanggan |
| Pesan "not modified" berulang | Isi pesan sama persis | Normal, bot mengabaikan |
| Tombol lama tidak merespon | Pesan utama sudah dihapus manual | Kirim `/start` untuk membuat pesan baru |
| `could not find driver` | Ekstensi `pdo_sqlite` hilang | `pkg install php` (Termux) / `apt install php-sqlite3` |
| Notifikasi tidak masuk | `notif_enabled` OFF atau `TG_CHAT_ID` salah | Aktifkan lewat tombol 🔔, cek `TG_CHAT_ID` |
| Jam tidak sesuai | Timezone | Set `date_default_timezone_set('Asia/Jakarta')` |

### Log & debug

Bot menulis log ke stdout. Untuk melihat error PHP secara detail:

```bash
php -d display_errors=1 -d error_reporting=E_ALL bot.php
```

Cek queue langsung di router:

```routeros
/queue simple print where target~"<IP-PELANGGAN>"
```

## FAQ

**Apakah butuh IP publik atau port forwarding?**
Tidak. Bot memakai long polling (koneksi keluar), jadi aman di balik NAT/4G.

**Apakah butuh scheduler MikroTik?**
Tidak. Bot memeriksa sendiri tiap 60 detik.

**Bisa untuk pelanggan PPPoE, bukan IP statik?**
Bisa, selama ada simple queue dengan `target=` berisi IP pelanggan. Bot mencocokkan berdasarkan `target`, bukan nama queue.

**Datanya hilang kalau bot di-restart?**
Tidak. Semua data di `data/app.db` (SQLite). State percakapan juga tersimpan di DB.

**Bisa multi-admin?**
Versi ini single-admin (`ADMIN_TG_ID`). Untuk multi-admin perlu perubahan pada pengecekan ID dan `bot_ui`/`bot_state` per chat.

**Bisa ganti `ISOLIR_LIMIT` tanpa mengubah data user?**
Ya, ubah di `config.php`. Berlaku untuk isolir berikutnya; user yang sedang terisolir perlu unisolir → isolir ulang agar nilai baru dipakai.

**Bagaimana backup?**
Cukup salin `data/app.db` (hentikan bot dulu, atau copy beserta file `-wal`).

**Kenapa hanya 5 file di zip awal tapi sekarang 9?**
Versi awal masih satu file besar. Versi ini dipecah per tanggung jawab agar mudah dirawat dan diuji.

---

## Lisensi

MIT License — bebas dipakai, diubah, dan didistribusikan dengan tetap mencantumkan atribusi.

```
Copyright (c) 2026

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

<p align="center">
  Dibuat untuk ISP kecil yang ingin isolir otomatis tanpa ribet.<br>
  <sub>⭐ Kalau berguna, kasih bintang.</sub>
</p>
