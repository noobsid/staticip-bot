<?php
/**
 * STATIC-IP BOT — semua tampilan (view) pesan utama.
 *
 * Setiap fungsi view_* hanya menggambar isi pesan utama yang sama.
 * Tidak ada yang mengirim pesan baru selain render() saat pesan lama hilang.
 */

// ============================ KOMPONEN ============================

function btn(string $text, string $cb): array
{
    return ['text' => $text, 'callback_data' => $cb];
}

function val(?string $s): string
{
    $s = trim((string)$s);
    return $s === '' ? '—' : $s;
}

function head(string $title, string $status = ''): string
{
    return $status === '' ? $title . "\n" : $title . "\n" . $status . "\n";
}

// ============================ KEYBOARD ============================

function menu_kb(): array
{
    return [
        [btn('📋 List User', 'list:0'), btn('➕ Tambah User', 'add')],
        [btn('📊 Ringkasan', 'summary')],
    ];
}

// ============================ FIELD DRAFT (tambah user) ============================

/**
 * Definisi field form tambah user. Urutan = urutan tombol.
 * key => [label tombol, label prompt, contoh/nilai default]
 */
function add_fields(): array
{
    return [
        'nama'         => ['Nama', 'nama pelanggan', ''],
        'ip'           => ['IP', 'IP address', '192.168.3.54'],
        'tgl_bayar'    => ['Tgl Bayar', "tanggal bayar (YYYY-MM-DD / 'today')", 'today'],
        'max_upload'   => ['Max Up', 'max upload', '1M'],
        'max_download' => ['Max Down', 'max download', '1M'],
        'catatan'      => ['Catatan', "catatan ('-' untuk kosong)", '-'],
    ];
}

/** Nilai default draft tambah user. */
function add_draft(): array
{
    return [
        'nama'         => '',
        'ip'           => '',
        'tgl_bayar'    => today(),
        'max_upload'   => '1M',
        'max_download' => '1M',
        'catatan'      => '',
    ];
}

function draft_valid(array $d): bool
{
    return $d['nama'] !== '' && filter_var($d['ip'], FILTER_VALIDATE_IP) !== false;
}

function draft_text(array $d, string $status = ''): string
{
    $t = head('➕ TAMBAH USER BARU', $status);
    $t .= "Isolir otomatis: " . add_days($d['tgl_bayar'], ISOLIR_DAYS) . "\n\n";
    $t .= "Nama: " . val($d['nama']) . "\n";
    $t .= "IP: " . val($d['ip']) . "\n";
    $t .= "Tgl Bayar: " . val($d['tgl_bayar']) . "\n";
    $t .= "Max Up: " . val($d['max_upload']) . "\n";
    $t .= "Max Down: " . val($d['max_download']) . "\n";
    $t .= "Catatan: " . val($d['catatan']) . "\n\n";
    $t .= draft_valid($d)
        ? "Ketuk 💾 Simpan untuk menyimpan."
        : "Lengkapi Nama dan IP dulu (tombol 💾 Simpan muncul otomatis).";
    return $t;
}

function draft_kb(array $d): array
{
    $kb = [];
    $row = [];
    foreach (add_fields() as $key => [$label]) {
        $row[] = btn($label, "af:$key");
        if (count($row) === 3) {
            $kb[] = $row;
            $row = [];
        }
    }
    if ($row) {
        $kb[] = $row;
    }
    if (draft_valid($d)) {
        $kb[] = [btn('💾 Simpan', 'af:save')];
    }
    $kb[] = [btn('❌ Batal', 'menu')];
    return $kb;
}

// ============================ FIELD EDIT (user lama) ============================

function edit_fields(): array
{
    return [
        'nama'         => ['Nama', 'nama pelanggan'],
        'ip'           => ['IP', 'IP address'],
        'tgl_bayar'    => ['Tgl Bayar', "tanggal bayar (YYYY-MM-DD / 'today')"],
        'max_upload'   => ['Max Up', 'max upload'],
        'max_download' => ['Max Down', 'max download'],
        'catatan'      => ['Catatan', "catatan ('-' untuk kosong)"],
    ];
}

// ============================ VIEW UTAMA ============================

function view_menu(string $status = ''): void
{
    store_view(UI_KEY, 'menu');
    ui(head('👋 STATIC-IP BOT', $status) . 'Pilih menu di bawah.', menu_kb());
}

function view_add(?array $draft = null, string $status = ''): void
{
    $d = $draft ?? add_draft();
    set_state('add:form', $d);
    store_view(UI_KEY, 'add', $d);
    ui(draft_text($d, $status), draft_kb($d));
}

function view_list(int $page = 0, string $status = ''): void
{
    $pdo = db();
    $per = 8;
    $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = max(0, min($page, $pages - 1));
    $off = $page * $per;

    $rows = $pdo->query("SELECT * FROM users ORDER BY tgl_isolir ASC LIMIT $per OFFSET $off")->fetchAll();

    store_view(UI_KEY, 'list', ['page' => $page]);

    $text = head("📋 LIST USER — $total data (hal " . ($page + 1) . "/$pages)", $status);
    $text .= $rows ? 'Ketuk nama untuk buka detail.' : 'Belum ada user. Ketuk ➕ Tambah User.';

    $kb = [];
    foreach ($rows as $u) {
        $dot = $u['status'] === 'isolir' ? '🔴' : '🟢';
        $kb[] = [btn("$dot {$u['nama']} — {$u['ip']}", "user:{$u['id']}")];
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
    $kb[] = [btn('➕ Tambah User', 'add'), btn('⬅️ Menu', 'menu')];

    ui($text, $kb);
}

function view_user(int $id, string $status = ''): void
{
    $st = db()->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) {
        view_list(0, '❌ User tidak ditemukan.');
        return;
    }

    store_view(UI_KEY, 'user', ['id' => $id]);

    $stat = $u['status'] === 'isolir' ? '🔴 ISOLIR' : '🟢 AKTIF';
    $notif = ((int)$u['notif_enabled'] === 1) ? 'ON' : 'OFF';

    $text = head("👤 {$u['nama']}", $status)
        . "IP: {$u['ip']}\n"
        . "Status: $stat\n"
        . "Tgl Bayar: {$u['tgl_bayar']}\n"
        . "Tgl Isolir: {$u['tgl_isolir']}\n"
        . "Limit normal: {$u['max_upload']}/{$u['max_download']}\n"
        . "Notif: $notif\n"
        . "Catatan: " . val($u['catatan']);

    $kb = [];
    $kb[] = $u['status'] === 'aktif'
        ? [btn('🔒 Isolir', "user:$id:isolir")]
        : [btn('🔓 Unisolir', "user:$id:unisolir")];
    $kb[] = [btn('⏫ Perpanjang', "user:$id:perpanjang"), btn('📅 Edit Isolir', "user:$id:editisolir")];
    $kb[] = [btn("🔔 Notif: $notif", "user:$id:toggle"), btn('✏️ Edit', "user:$id:editmenu")];
    $kb[] = [btn('🗑 Hapus', "user:$id:del"), btn('⬅️ Kembali', 'list:0')];

    ui($text, $kb);
}

function view_edit_menu(int $id, string $status = ''): void
{
    $st = db()->prepare("SELECT nama FROM users WHERE id = ?");
    $st->execute([$id]);
    $nama = $st->fetchColumn();
    if ($nama === false) {
        view_list(0, '❌ User tidak ditemukan.');
        return;
    }

    store_view(UI_KEY, 'edit', ['id' => $id]);

    $kb = [];
    $row = [];
    foreach (edit_fields() as $key => [$label]) {
        $row[] = btn($label, "ef:$id:$key");
        if (count($row) === 3) {
            $kb[] = $row;
            $row = [];
        }
    }
    if ($row) {
        $kb[] = $row;
    }
    $kb[] = [btn('⬅️ Kembali', "user:$id")];

    ui(head("✏️ EDIT — $nama", $status) . 'Pilih field yang mau diubah.', $kb);
}

function view_summary(string $status = ''): void
{
    $pdo = db();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $aktif = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='aktif'")->fetchColumn();
    $isolir = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='isolir'")->fetchColumn();
    $dueSoon = (int)$pdo->query(
        "SELECT COUNT(*) FROM users WHERE status='aktif' AND tgl_isolir <= date('now','+7 days','localtime')"
    )->fetchColumn();

    store_view(UI_KEY, 'summary');

    $text = head('📊 RINGKASAN', $status)
        . "Total: $total\n"
        . "🟢 Aktif: $aktif\n"
        . "🔴 Isolir: $isolir\n"
        . "⏳ Jatuh tempo ≤7 hari: $dueSoon";

    ui($text, menu_kb());
}

/** Tampilan prompt input: menimpa pesan utama, tidak menambah pesan baru. */
function view_prompt(string $title, string $hint, array $kb): void
{
    store_view(UI_KEY, 'prompt');
    ui($title . "\n\nKirim $hint.\nKetik 'batal' untuk membatalkan.", $kb);
}
