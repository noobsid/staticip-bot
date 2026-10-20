<?php
/**
 * STATIC-IP BOT — antarmuka Telegram + scheduler isolir otomatis.
 *
 * Jalankan:  php bot.php
 * Bot memakai long-polling (getUpdates loop), cocok untuk HP di belakang NAT.
 * Setiap ~60 detik bot juga cek user jatuh tempo → isolir otomatis + notif.
 * Tidak perlu scheduler MikroTik lagi.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (TG_BOT_TOKEN === '') {
    fwrite(STDERR, "TG_BOT_TOKEN kosong. Isi config.php dulu.\n");
    exit(1);
}

// ============================ STATE ============================

function set_state(string $state, array $data = []): void
{
    $pdo = db();
    $pdo->prepare("INSERT OR REPLACE INTO bot_state (chat_id, state, data) VALUES (?, ?, ?)")
        ->execute([ADMIN_TG_ID, $state, json_encode($data)]);
}

function get_state(): ?array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT state, data FROM bot_state WHERE chat_id = ?");
    $st->execute([ADMIN_TG_ID]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    return ['state' => $row['state'], 'data' => json_decode($row['data'], true) ?: []];
}

function clear_state(): void
{
    $pdo = db();
    $pdo->prepare("DELETE FROM bot_state WHERE chat_id = ?")->execute([ADMIN_TG_ID]);
}

// ============================ TELEGRAM HELPERS ============================

function inline_kb(array $rows): array
{
    return ['inline_keyboard' => $rows];
}

function btn(string $text, string $cb): array
{
    return ['text' => $text, 'callback_data' => $cb];
}

function menu_kb(): array
{
    return inline_kb([
        [btn('📋 List User', 'list:0'), btn('➕ Tambah User', 'add')],
        [btn('📊 Ringkasan', 'summary')],
    ]);
}

function send(string $text, ?array $kb = null): void
{
    $p = ['chat_id' => ADMIN_TG_ID, 'text' => $text];
    if ($kb !== null) {
        $p['reply_markup'] = $kb;
    }
    tg_api('sendMessage', $p);
}

function edit_msg(string $chat_id, int $msg_id, string $text, ?array $kb = null): void
{
    $p = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text];
    if ($kb !== null) {
        $p['reply_markup'] = $kb;
    }
    tg_api('editMessageText', $p);
}

function answer_cb(string $cb_id, string $text = ''): void
{
    tg_api('answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => $text]);
}

// ============================ RENDER ============================

function user_line(array $u): string
{
    $s = $u['status'] === 'isolir' ? '🔴' : '🟢';
    return "$s {$u['nama']} — {$u['ip']}";
}

function render_list(int $page, string $chat_id, int $msg_id): void
{
    $pdo = db();
    $per = 8;
    $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = max(0, min($page, $pages - 1));
    $off = $page * $per;

    $rows = $pdo->query("SELECT * FROM users ORDER BY tgl_isolir ASC LIMIT $per OFFSET $off")->fetchAll();

    $text = "📋 List User ($total)";
    $kb = [];
    foreach ($rows as $u) {
        $kb[] = [btn(user_line($u), "user:{$u['id']}")];
    }
    $nav = [];
    if ($page > 0) {
        $nav[] = btn('⬅️', 'list:' . ($page - 1));
    }
    if ($page < $pages - 1) {
        $nav[] = btn('➡️', 'list:' . ($page + 1));
    }
    if ($nav) {
        $kb[] = $nav;
    }
    $kb[] = [btn('⬅️ Menu', 'menu')];

    edit_msg($chat_id, $msg_id, $text, inline_kb($kb));
}

function render_user(int $id, string $chat_id, int $msg_id): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        edit_msg($chat_id, $msg_id, "User tidak ditemukan.", menu_kb());
        return;
    }

    $status = $u['status'] === 'isolir' ? '🔴 ISOLIR' : '🟢 AKTIF';
    $notif = $u['notif_enabled'] ? 'ON' : 'OFF';
    $text = "👤 {$u['nama']}\n"
        . "IP: {$u['ip']}\n"
        . "Status: $status\n"
        . "Tgl Bayar: {$u['tgl_bayar']}\n"
        . "Tgl Isolir: {$u['tgl_isolir']}\n"
        . "Limit normal: {$u['max_upload']}/{$u['max_download']}\n"
        . "Notif: $notif\n"
        . "Catatan: " . ($u['catatan'] !== '' ? $u['catatan'] : '-');

    $kb = [];
    if ($u['status'] === 'aktif') {
        $kb[] = [btn('🔒 Isolir', "user:$id:isolir")];
    } else {
        $kb[] = [btn('🔓 Unisolir', "user:$id:unisolir")];
    }
    $kb[] = [btn('⏫ Perpanjang', "user:$id:perpanjang"), btn('📅 Edit Isolir', "user:$id:editisolir")];
    $kb[] = [btn("🔔 Notif: $notif", "user:$id:toggle"), btn('✏️ Edit', "user:$id:editmenu")];
    $kb[] = [btn('🗑 Hapus', "user:$id:del"), btn('⬅️ Kembali', 'list:0')];

    edit_msg($chat_id, $msg_id, $text, inline_kb($kb));
}

function render_edit_menu(int $id, string $chat_id, int $msg_id): void
{
    $kb = inline_kb([
        [btn('Nama', "edit:$id:nama"), btn('IP', "edit:$id:ip")],
        [btn('Tgl Bayar', "edit:$id:tgl_bayar"), btn('Max Up', "edit:$id:max_upload")],
        [btn('Max Down', "edit:$id:max_download"), btn('Catatan', "edit:$id:catatan")],
        [btn('⬅️ Kembali', "user:$id")],
    ]);
    edit_msg($chat_id, $msg_id, "✏️ Pilih field yang mau diedit:", $kb);
}

function render_summary(string $chat_id, int $msg_id): void
{
    $pdo = db();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $aktif = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='aktif'")->fetchColumn();
    $isolir = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='isolir'")->fetchColumn();
    $dueSoon = (int)$pdo->query(
        "SELECT COUNT(*) FROM users WHERE status='aktif' AND tgl_isolir <= date('now','+7 days','localtime')"
    )->fetchColumn();

    $text = "📊 Ringkasan\n"
        . "Total: $total\n"
        . "🟢 Aktif: $aktif\n"
        . "🔴 Isolir: $isolir\n"
        . "⏳ Jatuh tempo ≤7 hari: $dueSoon";

    edit_msg($chat_id, $msg_id, $text, menu_kb());
}

// ============================ AKSI ============================

function do_isolir(int $id, string $chat_id, int $msg_id): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        edit_msg($chat_id, $msg_id, "User tidak ditemukan.", menu_kb());
        return;
    }

    $msg = '';
    try {
        $api = mk_connect();
        if (isolate_user($api, $u)) {
            $pdo->prepare("UPDATE users SET status='isolir', last_notif_sent=NULL WHERE id=?")->execute([$id]);
            if ((int)$u['notif_enabled'] === 1) {
                $text = "🔒 [Isolir] {$u['nama']} — IP {$u['ip']}\nLimit diubah ke " . ISOLIR_LIMIT . ".";
                if (send_telegram($text)) {
                    $pdo->prepare("UPDATE users SET last_notif_sent=? WHERE id=?")->execute([today(), $id]);
                }
            }
            $msg = "✅ {$u['nama']} diisolir (limit " . ISOLIR_LIMIT . ").";
        } else {
            $msg = "❌ Queue untuk IP {$u['ip']} tidak ditemukan.";
        }
        $api->disconnect();
    } catch (Throwable $e) {
        $msg = "❌ " . $e->getMessage();
    }

    render_user($id, $chat_id, $msg_id);
    send($msg);
}

function do_unisolir(int $id, string $chat_id, int $msg_id): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        edit_msg($chat_id, $msg_id, "User tidak ditemukan.", menu_kb());
        return;
    }

    $msg = '';
    try {
        $api = mk_connect();
        if (restore_user($api, $u)) {
            $pdo->prepare("UPDATE users SET status='aktif', last_notif_sent=NULL WHERE id=?")->execute([$id]);
            $msg = "✅ {$u['nama']} di-unisolir. Limit {$u['max_upload']}/{$u['max_download']}.";
        } else {
            $msg = "❌ Queue untuk IP {$u['ip']} tidak ditemukan.";
        }
        $api->disconnect();
    } catch (Throwable $e) {
        $msg = "❌ " . $e->getMessage();
    }

    render_user($id, $chat_id, $msg_id);
    send($msg);
}

function do_toggle(int $id, string $chat_id, int $msg_id): void
{
    $pdo = db();
    $pdo->prepare("UPDATE users SET notif_enabled = 1 - notif_enabled WHERE id=?")->execute([$id]);
    render_user($id, $chat_id, $msg_id);
}

function confirm_delete(int $id, string $chat_id, int $msg_id): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT nama FROM users WHERE id = ?");
    $st->execute([$id]);
    $nama = $st->fetchColumn();
    $kb = inline_kb([
        [btn('✅ Ya, hapus', "user:$id:delconfirm")],
        [btn('❌ Batal', "user:$id")],
    ]);
    edit_msg($chat_id, $msg_id, "Yakin hapus $nama?", $kb);
}

function do_delete(int $id, string $chat_id, int $msg_id): void
{
    $pdo = db();
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    render_list(0, $chat_id, $msg_id);
    send("🗑 User dihapus.");
}

function do_perpanjang(int $id, string $tglBayar): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        clear_state();
        send("User tidak ditemukan.", menu_kb());
        return;
    }

    $tglIsolir = add_days($tglBayar, ISOLIR_DAYS);
    $pdo->prepare("UPDATE users SET tgl_bayar=?, tgl_isolir=?, status='aktif', last_notif_sent=NULL WHERE id=?")
        ->execute([$tglBayar, $tglIsolir, $id]);

    $restore = '';
    try {
        $api = mk_connect();
        $ok = restore_user($api, $u);
        $api->disconnect();
        $restore = $ok ? "\n✅ Limit di-restore ke normal." : "\n⚠️ Queue untuk IP ini tidak ditemukan.";
    } catch (Throwable $e) {
        $restore = "\n⚠️ Gagal konek MikroTik.";
    }

    clear_state();
    send("⏫ {$u['nama']} diperpanjang.\nTgl bayar: $tglBayar\nIsolir berikutnya: $tglIsolir" . $restore, menu_kb());
}

function finalize_add(array $data): void
{
    $pdo = db();
    $tglIsolir = add_days($data['tgl_bayar'], ISOLIR_DAYS);
    clear_state();
    try {
        $pdo->prepare(
            "INSERT INTO users (nama, ip, tgl_bayar, tgl_isolir, max_upload, max_download, catatan)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $data['nama'], $data['ip'], $data['tgl_bayar'], $tglIsolir,
            $data['max_upload'], $data['max_download'], $data['catatan'],
        ]);
        send("✅ {$data['nama']} ditambahkan.\nIP: {$data['ip']}\nIsolir otomatis: $tglIsolir", menu_kb());
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'UNIQUE') !== false) {
            send("❌ IP {$data['ip']} sudah terdaftar.", menu_kb());
        } else {
            send("❌ Gagal: " . $e->getMessage(), menu_kb());
        }
    }
}

// ============================ INPUT (teks) ============================

function parse_date(string $t): ?string
{
    $t = trim(strtolower($t));
    if ($t === 'today') {
        return today();
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) {
        return $t;
    }
    return null;
}

function handle_text(string $text, array $s): void
{
    $pdo = db();
    $state = $s['state'];
    $data = $s['data'];

    if (strtolower($text) === 'batal') {
        clear_state();
        send("❌ Dibatalkan.", menu_kb());
        return;
    }

    // --- alur tambah ---
    if ($state === 'add_nama') {
        $data['nama'] = $text;
        set_state('add_ip', $data);
        send("IP address (misal 192.168.3.54):");
        return;
    }
    if ($state === 'add_ip') {
        $ip = trim($text);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            send("❌ IP tidak valid. Ulangi:");
            return;
        }
        $data['ip'] = $ip;
        set_state('add_bayar', $data);
        send("Tanggal bayar (YYYY-MM-DD) atau 'today':");
        return;
    }
    if ($state === 'add_bayar') {
        $d = parse_date($text);
        if (!$d) {
            send("❌ Format tanggal salah. Kirim YYYY-MM-DD atau 'today':");
            return;
        }
        $data['tgl_bayar'] = $d;
        set_state('add_up', $data);
        send("Max upload normal (misal 5M):");
        return;
    }
    if ($state === 'add_up') {
        $data['max_upload'] = trim($text) ?: '1M';
        set_state('add_down', $data);
        send("Max download normal (misal 5M):");
        return;
    }
    if ($state === 'add_down') {
        $data['max_download'] = trim($text) ?: '1M';
        set_state('add_catatan', $data);
        send("Catatan (atau '-' untuk kosong):");
        return;
    }
    if ($state === 'add_catatan') {
        $data['catatan'] = ($text === '-' || $text === '') ? '' : $text;
        finalize_add($data);
        return;
    }

    // --- perpanjang ---
    if (strpos($state, 'perpanjang:') === 0) {
        $id = (int)substr($state, 11);
        $d = parse_date($text);
        if (!$d) {
            send("❌ Format tanggal salah. Ulangi (YYYY-MM-DD / 'today'):");
            return;
        }
        do_perpanjang($id, $d);
        return;
    }

    // --- edit isolir ---
    if (strpos($state, 'editisolir:') === 0) {
        $id = (int)substr($state, 11);
        $d = parse_date($text);
        if (!$d) {
            send("❌ Format tanggal salah. Ulangi:");
            return;
        }
        $pdo->prepare("UPDATE users SET tgl_isolir=? WHERE id=?")->execute([$d, $id]);
        clear_state();
        send("📅 Tanggal isolir diubah jadi $d.", menu_kb());
        return;
    }

    // --- edit field ---
    if (strpos($state, 'edit:') === 0) {
        $parts = explode(':', $state);
        $id = (int)$parts[1];
        $field = $parts[2] ?? '';
        $val = trim($text);

        if ($field === 'ip' && !filter_var($val, FILTER_VALIDATE_IP)) {
            send("❌ IP tidak valid. Ulangi:");
            return;
        }
        if ($field === 'tgl_bayar') {
            $d = parse_date($val);
            if (!$d) {
                send("❌ Tanggal tidak valid. Ulangi:");
                return;
            }
            $tglIsolir = add_days($d, ISOLIR_DAYS);
            $pdo->prepare("UPDATE users SET tgl_bayar=?, tgl_isolir=? WHERE id=?")->execute([$d, $tglIsolir, $id]);
        } elseif ($field === 'catatan') {
            $pdo->prepare("UPDATE users SET catatan=? WHERE id=?")->execute([($val === '-' ? '' : $val), $id]);
        } else {
            // field dari callback_data milik kita sendiri (aman dari injeksi)
            $pdo->prepare("UPDATE users SET $field=? WHERE id=?")->execute([$val, $id]);
        }

        clear_state();
        send("✅ Field diperbarui.");
        return;
    }

    // fallback
    clear_state();
    send("👋 Pilih menu:", menu_kb());
}

// ============================ CALLBACK ============================

function handle_callback(array $cb): void
{
    $cb_id = $cb['id'];
    $data = $cb['data'] ?? '';
    $msg = $cb['message'] ?? null;
    $chat_id = (string)($msg['chat']['id'] ?? '');
    $msg_id = (int)($msg['message_id'] ?? 0);

    if ($chat_id !== ADMIN_TG_ID) {
        answer_cb($cb_id);
        return;
    }
    answer_cb($cb_id);
    clear_state(); // tombol apa pun membatalkan alur input yang tertunda

    if ($data === 'menu') {
        edit_msg($chat_id, $msg_id, "👋 Pilih menu:", menu_kb());
        return;
    }
    if ($data === 'summary') {
        render_summary($chat_id, $msg_id);
        return;
    }
    if ($data === 'add') {
        set_state('add_nama', []);
        send("➕ Tambah user baru.\nKirim nama pelanggan (ketik 'batal' untuk batal).");
        return;
    }
    if (strpos($data, 'list:') === 0) {
        render_list((int)substr($data, 5), $chat_id, $msg_id);
        return;
    }
    if (strpos($data, 'edit:') === 0) {
        $parts = explode(':', $data); // edit, id, field
        $id = (int)$parts[1];
        $field = $parts[2] ?? '';
        $labels = [
            'nama' => 'nama',
            'ip' => 'IP address',
            'tgl_bayar' => "tanggal bayar (YYYY-MM-DD / 'today')",
            'max_upload' => 'max upload (misal 5M)',
            'max_download' => 'max download (misal 5M)',
            'catatan' => 'catatan',
        ];
        $label = $labels[$field] ?? $field;
        set_state("edit:$id:$field");
        send("✏️ Kirim $label baru (ketik 'batal' untuk batal):");
        return;
    }
    if (strpos($data, 'user:') === 0) {
        $parts = explode(':', $data); // user, id, [action]
        $id = (int)$parts[1];
        $action = $parts[2] ?? '';
        switch ($action) {
            case '':
                render_user($id, $chat_id, $msg_id);
                break;
            case 'isolir':
                do_isolir($id, $chat_id, $msg_id);
                break;
            case 'unisolir':
                do_unisolir($id, $chat_id, $msg_id);
                break;
            case 'perpanjang':
                set_state("perpanjang:$id");
                send("⏫ Kirim tanggal bayar baru (YYYY-MM-DD) atau 'today':");
                break;
            case 'editisolir':
                set_state("editisolir:$id");
                send("📅 Kirim tanggal isolir baru (YYYY-MM-DD) atau 'today':");
                break;
            case 'toggle':
                do_toggle($id, $chat_id, $msg_id);
                break;
            case 'editmenu':
                render_edit_menu($id, $chat_id, $msg_id);
                break;
            case 'del':
                confirm_delete($id, $chat_id, $msg_id);
                break;
            case 'delconfirm':
                do_delete($id, $chat_id, $msg_id);
                break;
        }
        return;
    }
}

function handle_message(array $msg): void
{
    $chat_id = (string)($msg['chat']['id'] ?? '');
    if ($chat_id !== ADMIN_TG_ID) {
        return;
    }
    $text = trim($msg['text'] ?? '');
    $s = get_state();
    if ($s !== null && $s['state'] !== 'idle') {
        handle_text($text, $s);
        return;
    }
    send("👋 Pilih menu:", menu_kb());
}

// ============================ ISOLASI OTOMATIS ============================

function run_isolation(): string
{
    $pdo = db();
    $today = today();
    $log = [];

    $due = $pdo->query("SELECT * FROM users WHERE status='aktif' AND tgl_isolir <= '$today'")->fetchAll();
    if ($due) {
        try {
            $api = mk_connect();
            foreach ($due as $u) {
                if (isolate_user($api, $u)) {
                    $pdo->prepare("UPDATE users SET status='isolir', last_notif_sent=NULL WHERE id=?")->execute([$u['id']]);
                    $log[] = "ISOLIR: {$u['nama']}";
                } else {
                    $log[] = "GAGAL queue: {$u['ip']}";
                }
            }
            $api->disconnect();
        } catch (Throwable $e) {
            $log[] = "ERROR: " . $e->getMessage();
        }
    }

    foreach (notify_isolated_users($pdo) as $n) {
        $log[] = "TELEGRAM: $n";
    }

    return implode("\n", $log);
}

// ============================ MAIN LOOP ============================

fwrite(STDOUT, "Bot jalan. Tekan Ctrl+C untuk berhenti.\n");

$offset = 0;
$lastCheck = 0;
while (true) {
    $res = tg_api('getUpdates', ['offset' => $offset, 'timeout' => 25]);

    if (($res['ok'] ?? false) === true) {
        foreach ($res['result'] as $u) {
            $offset = $u['update_id'] + 1;
            if (isset($u['callback_query'])) {
                handle_callback($u['callback_query']);
            } elseif (isset($u['message'])) {
                handle_message($u['message']);
            }
        }
    } else {
        fwrite(STDERR, 'getUpdates error: ' . json_encode($res) . "\n");
        sleep(5);
    }

    if (time() - $lastCheck >= 60) {
        $lastCheck = time();
        $log = run_isolation();
        if ($log !== '') {
            fwrite(STDOUT, date('[Y-m-d H:i:s] ') . str_replace("\n", ' | ', $log) . "\n");
        }
    }
}
