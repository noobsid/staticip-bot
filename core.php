<?php
/**
 * STATIC-IP BOT — core: state input, penyimpanan pointer pesan utama, render.
 *
 * PRINSIP UI (anti pesan menumpuk):
 *  - Hanya ada SATU "pesan utama" per chat (UI_KEY). Semua tampilan — menu,
 *    list, detail, form tambah, form edit, prompt, hasil aksi — DI-EDIT di
 *    pesan itu, tidak pernah dikirim sebagai pesan baru.
 *  - Prompt "kirim nilai X" menimpa isi pesan utama, bukan pesan baru.
 *  - Pesan teks dari admin dihapus otomatis setelah diproses.
 *  - Notifikasi isolir memakai SATU pesan notifikasi (NOTIF_KEY) yang di-edit
 *    ulang, bukan pesan baru tiap kejadian.
 *  - Pesan sementara (fallback) dicatat di bot_temp dan dibersihkan otomatis.
 */

// ============================ STATE INPUT ============================

function set_state(string $state, array $data = []): void
{
    db()->prepare("INSERT OR REPLACE INTO bot_state (chat_id, state, data) VALUES (?, ?, ?)")
        ->execute([ADMIN_TG_ID, $state, json_encode($data)]);
}

function get_state(): ?array
{
    $st = db()->prepare("SELECT state, data FROM bot_state WHERE chat_id = ?");
    $st->execute([ADMIN_TG_ID]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return ['state' => (string)$row['state'], 'data' => json_decode((string)$row['data'], true) ?: []];
}

function clear_state(): void
{
    db()->prepare("DELETE FROM bot_state WHERE chat_id = ?")->execute([ADMIN_TG_ID]);
}

// ============================ STORE PESAN UI ============================

function store_get(string $key): ?array
{
    $st = db()->prepare("SELECT * FROM bot_ui WHERE chat_id = ?");
    $st->execute([$key]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return [
        'message_id' => (int)$row['message_id'],
        'view'       => (string)$row['view'],
        'data'       => json_decode((string)$row['data'], true) ?: [],
    ];
}

function store_save(string $key, int $message_id, string $view, array $data): void
{
    db()->prepare(
        "INSERT OR REPLACE INTO bot_ui (chat_id, message_id, view, data, time_updated)
         VALUES (?, ?, ?, ?, datetime('now','localtime'))"
    )->execute([$key, $message_id, $view, json_encode($data)]);
}

/** Tandai view aktif tanpa mengubah pointer message_id. */
function store_view(string $key, string $view, array $data = []): void
{
    $cur = store_get($key);
    store_save($key, $cur['message_id'] ?? 0, $view, $data);
}

// ============================ RENDER ============================

/**
 * Render ke SATU pesan: edit pesan lama; kalau sudah tidak bisa diedit,
 * kirim baru lalu simpan id-nya sebagai pesan utama yang baru.
 */
function render(string $key, string $chat_id, string $text, array $kb = []): void
{
    $markup = ['inline_keyboard' => $kb];
    $cur = store_get($key);

    if ($cur !== null && $cur['message_id'] > 0) {
        $r = tg_api('editMessageText', [
            'chat_id'      => $chat_id,
            'message_id'   => $cur['message_id'],
            'text'         => $text,
            'reply_markup' => $markup,
        ]);
        if (($r['ok'] ?? false) === true) {
            return;
        }
        if (stripos((string)($r['description'] ?? ''), 'not modified') !== false) {
            return;
        }
    }

    $r = tg_api('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => $markup]);
    if (($r['ok'] ?? false) === true) {
        store_save($key, (int)($r['result']['message_id'] ?? 0), $cur['view'] ?? '', $cur['data'] ?? []);
    }
}

/** Gambar pesan utama admin (membersihkan pesan sementara lebih dulu). */
function ui(string $text, array $kb = []): void
{
    temp_purge();
    render(UI_KEY, ADMIN_TG_ID, $text, $kb);
}

/** Gambar pesan notifikasi (satu pesan, di-edit ulang). */
function notify_ui(string $text, array $kb = []): void
{
    render(NOTIF_KEY, TG_CHAT_ID, $text, $kb);
}

// ============================ PESAN SEMENTARA ============================

/** Hapus semua pesan sementara yang tercatat (termasuk sisa run sebelumnya). */
function temp_purge(): void
{
    $st = db()->prepare("SELECT id, message_id FROM bot_temp WHERE chat_id = ?");
    $st->execute([ADMIN_TG_ID]);
    foreach ($st->fetchAll() as $r) {
        tg_delete_message((int)$r['message_id']);
        db()->prepare("DELETE FROM bot_temp WHERE id = ?")->execute([(int)$r['id']]);
    }
}
