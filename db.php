<?php
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        init_schema($pdo);
    }
    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        nama            TEXT NOT NULL,
        ip              TEXT NOT NULL UNIQUE,
        tgl_bayar       TEXT NOT NULL,
        tgl_isolir      TEXT NOT NULL,
        max_upload      TEXT NOT NULL DEFAULT '1M',
        max_download    TEXT NOT NULL DEFAULT '1M',
        catatan         TEXT NOT NULL DEFAULT '',
        status          TEXT NOT NULL DEFAULT 'aktif',
        notif_enabled   INTEGER NOT NULL DEFAULT 1,
        last_notif_sent TEXT DEFAULT NULL,
        created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // state percakapan bot (input teks bertahap)
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_state (
        chat_id TEXT PRIMARY KEY,
        state   TEXT NOT NULL,
        data    TEXT NOT NULL DEFAULT '{}'
    )");

    // pointer ke SATU pesan UI utama yang selalu di-edit (anti menumpuk)
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_ui (
        chat_id TEXT PRIMARY KEY,
        message_id INTEGER NOT NULL,
        view    TEXT NOT NULL DEFAULT '',
        data    TEXT NOT NULL DEFAULT '{}',
        time_updated TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // antrean pesan sementara (prompt / hasil aksi) yang wajib dihapus lagi
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_temp (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chat_id TEXT NOT NULL,
        message_id INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}
