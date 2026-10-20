<?php
// ============================================================
// STATIC-IP BOT — konfigurasi
// ============================================================

// --- Database (SQLite) ---
define('DB_FILE', __DIR__ . '/data/app.db');

// --- MikroTik (RouterOS API port 8728) ---
define('MK_HOST', 'IP-OR-HOST');
define('MK_PORT', PORT);
define('MK_USER', 'YOUR_USER');
define('MK_PASS', 'YOUR_PASS');

// --- Aturan isolir ---
define('ISOLIR_DAYS', 32);           // tgl isolir = tgl bayar + N hari
define('ISOLIR_LIMIT', '16k/16k');   // limit saat isolir (upload/download)

// --- Telegram ---
define('TG_BOT_TOKEN', 'YOUR_TOKEN');
define('TG_CHAT_ID', 'YOUR_CHATID');   // untuk notifikasi isolir
define('ADMIN_TG_ID', 'ADMIN_CHATID');  // HANYA ID ini yang boleh pakai bot. bisa sama dangan id atas

// --- Error handling (production) ---
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// --- Zona waktu ---
date_default_timezone_set('Asia/Jakarta');
