<?php
/**
 * STATIC-IP BOT — aksi (isolir, restore, simpan, hapus, isolir otomatis).
 *
 * Semua fungsi di sini TIDAK mengirim pesan sendiri. Mereka mengembalikan
 * string status yang kemudian ditampilkan di pesan utama (view_*).
 */

// ============================ AMBIL USER ============================

function get_user(int $id): ?array
{
    $st = db()->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    return $u ?: null;
}

// ============================ ISOLIR / UNISOLIR ============================

function action_isolir(int $id): string
{
    $pdo = db();
    $u = get_user($id);
    if (!$u) {
        return '❌ User tidak ditemukan.';
    }

    try {
        $api = mk_connect();
        $ok = isolate_user($api, $u);
        $api->disconnect();
        if (!$ok) {
            return "❌ Queue untuk IP {$u['ip']} tidak ditemukan.";
        }
        $pdo->prepare("UPDATE users SET status='isolir', last_notif_sent=NULL WHERE id=?")->execute([$id]);
        return "✅ {$u['nama']} diisolir (limit " . ISOLIR_LIMIT . ').';
    } catch (Throwable $e) {
        return '❌ ' . $e->getMessage();
    }
}

function action_unisolir(int $id): string
{
    $pdo = db();
    $u = get_user($id);
    if (!$u) {
        return '❌ User tidak ditemukan.';
    }

    try {
        $api = mk_connect();
        $ok = restore_user($api, $u);
        $api->disconnect();
        if (!$ok) {
            return "❌ Queue untuk IP {$u['ip']} tidak ditemukan.";
        }
        $pdo->prepare("UPDATE users SET status='aktif', last_notif_sent=NULL WHERE id=?")->execute([$id]);
        return "✅ {$u['nama']} di-unisolir. Limit {$u['max_upload']}/{$u['max_download']}.";
    } catch (Throwable $e) {
        return '❌ ' . $e->getMessage();
    }
}

function action_toggle_notif(int $id): string
{
    $pdo = db();
    $pdo->prepare("UPDATE users SET notif_enabled = 1 - notif_enabled WHERE id=?")->execute([$id]);
    $u = get_user($id);
    if (!$u) {
        return '❌ User tidak ditemukan.';
    }
    return '🔔 Notifikasi ' . (((int)$u['notif_enabled'] === 1) ? 'diaktifkan.' : 'dimatikan.');
}

function action_delete(int $id): string
{
    $u = get_user($id);
    if (!$u) {
        return '❌ User tidak ditemukan.';
    }
    db()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    return "🗑 {$u['nama']} dihapus.";
}

// ============================ SIMPAN TAMBAH USER ============================

function action_save_add(array $d): string
{
    $pdo = db();
    $tglIsolir = add_days($d['tgl_bayar'], ISOLIR_DAYS);

    if (!draft_valid($d)) {
        return '❌ Nama dan IP wajib diisi.';
    }

    try {
        $pdo->prepare(
            "INSERT INTO users (nama, ip, tgl_bayar, tgl_isolir, max_upload, max_download, catatan)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            trim($d['nama']),
            trim($d['ip']),
            $d['tgl_bayar'],
            $tglIsolir,
            $d['max_upload'],
            $d['max_download'],
            $d['catatan'],
        ]);
        return "✅ {$d['nama']} ditambahkan.\nIP: {$d['ip']}\nIsolir otomatis: $tglIsolir";
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'UNIQUE') !== false) {
            return "❌ IP {$d['ip']} sudah terdaftar. Ubah field IP dulu.";
        }
        return '❌ Gagal simpan: ' . $e->getMessage();
    }
}

// ============================ PERPANJANG & EDIT ============================

function action_perpanjang(int $id, string $tglBayar): string
{
    $pdo = db();
    $u = get_user($id);
    if (!$u) {
        return '❌ User tidak ditemukan.';
    }

    $tglIsolir = add_days($tglBayar, ISOLIR_DAYS);
    $pdo->prepare("UPDATE users SET tgl_bayar=?, tgl_isolir=?, status='aktif', last_notif_sent=NULL WHERE id=?")
        ->execute([$tglBayar, $tglIsolir, $id]);

    $extra = '';
    try {
        $api = mk_connect();
        $ok = restore_user($api, $u);
        $api->disconnect();
        $extra = $ok ? ' Limit direstore.' : ' ⚠️ Queue IP ini tidak ditemukan.';
    } catch (Throwable $e) {
        $extra = ' ⚠️ Gagal konek MikroTik.';
    }

    return "⏫ {$u['nama']} diperpanjang.\nTgl bayar: $tglBayar\nIsolir berikutnya: $tglIsolir" . $extra;
}

function action_edit_field(int $id, string $field, string $value): string
{
    $pdo = db();
    $u = get_user($id);
    if (!$u) {
        return '❌ User tidak ditemukan.';
    }

    if ($field === 'ip') {
        if (!filter_var($value, FILTER_VALIDATE_IP)) {
            return '❌ IP tidak valid.';
        }
        try {
            $pdo->prepare("UPDATE users SET ip=? WHERE id=?")->execute([$value, $id]);
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                return "❌ IP $value sudah dipakai user lain.";
            }
            return '❌ Gagal: ' . $e->getMessage();
        }
        return '✅ IP diperbarui.';
    }

    if ($field === 'tgl_bayar') {
        $tglIsolir = add_days($value, ISOLIR_DAYS);
        $pdo->prepare("UPDATE users SET tgl_bayar=?, tgl_isolir=? WHERE id=?")
            ->execute([$value, $tglIsolir, $id]);
        return "✅ Tgl bayar diperbarui. Isolir: $tglIsolir";
    }

    if ($field === 'tgl_isolir') {
        $pdo->prepare("UPDATE users SET tgl_isolir=? WHERE id=?")->execute([$value, $id]);
        return '✅ Tgl isolir diperbarui.';
    }

    // field aman: nama, max_upload, max_download, catatan
    $allowed = ['nama', 'max_upload', 'max_download', 'catatan'];
    if (!in_array($field, $allowed, true)) {
        return '❌ Field tidak dikenal.';
    }
    $pdo->prepare("UPDATE users SET $field=? WHERE id=?")->execute([$value, $id]);
    return '✅ Field diperbarui.';
}

// ============================ ISOLIR OTOMATIS ============================

function run_isolation(): string
{
    $pdo = db();
    $log = [];
    $today = today();

    $due = $pdo->query("SELECT * FROM users WHERE status='aktif' AND tgl_isolir <= '$today'")->fetchAll();

    if ($due) {
        try {
            $api = mk_connect();
            foreach ($due as $u) {
                if (isolate_user($api, $u)) {
                    $pdo->prepare("UPDATE users SET status='isolir', last_notif_sent=NULL WHERE id=?")
                        ->execute([(int)$u['id']]);
                    $log[] = "ISOLIR: {$u['nama']}";
                } else {
                    $log[] = "GAGAL queue: {$u['ip']}";
                }
            }
            $api->disconnect();
        } catch (Throwable $e) {
            $log[] = 'ERROR: ' . $e->getMessage();
        }
    }

    // Notifikasi agregat: SATU pesan notifikasi yang di-edit ulang,
    // bukan pesan baru setiap user.
    $notifs = take_isolated_notifications($pdo);
    if ($notifs) {
        $text = "🔔 NOTIFIKASI ISOLIR — " . date('d-m-Y H:i') . "\n\n"
            . implode("\n", $notifs)
            . "\n\nKetuk tombol di bawah untuk membuka daftar user.";
        notify_ui($text, [[btn('📋 Buka List User', 'list:0')]]);
        $log[] = 'NOTIF: ' . count($notifs) . ' user';
    }

    return implode("\n", $log);
}
