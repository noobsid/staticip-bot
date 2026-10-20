<?php
/**
 * STATIC-IP BOT — handler update Telegram (callback tombol & input teks).
 *
 * Aturan anti-menumpuk:
 *  - Setiap pesan teks dari admin DIHAPUS setelah dibaca (deleteMessage).
 *  - Prompt input tidak dikirim sebagai pesan baru; pesan utama yang di-edit.
 *  - Hasil aksi ditampilkan sebagai baris status di pesan utama.
 */

// ============================ PARSER ============================

function parse_date(string $t): ?string
{
    $t = trim(strtolower($t));
    if ($t === 'today' || $t === 'hari ini') {
        return today();
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) {
        return $t;
    }
    return null;
}

function is_cancel(string $t): bool
{
    $t = trim(strtolower($t));
    return $t === 'batal' || $t === 'cancel';
}

// ============================ CALLBACK ============================

function handle_callback(array $cb): void
{
    $cb_id  = (string)($cb['id'] ?? '');
    $data   = (string)($cb['data'] ?? '');
    $msg    = $cb['message'] ?? null;
    $chat   = (string)($msg['chat']['id'] ?? '');

    if ($chat !== ADMIN_TG_ID) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $cb_id]);
        return;
    }
    tg_api('answerCallbackQuery', ['callback_query_id' => $cb_id]);

    // --- navigasi dasar ---
    if ($data === 'menu') {
        clear_state();
        view_menu();
        return;
    }
    if ($data === 'summary') {
        clear_state();
        view_summary();
        return;
    }
    if (strpos($data, 'list:') === 0) {
        clear_state();
        view_list((int)substr($data, 5));
        return;
    }

    // --- form tambah user ---
    if ($data === 'add') {
        clear_state();
        view_add(add_draft());
        return;
    }
    if (strpos($data, 'af:') === 0) {
        $key = substr($data, 3);
        if ($key === 'save') {
            $s = get_state();
            $draft = $s['data'] ?? add_draft();
            $status = action_save_add($draft);
            clear_state();
            if (strpos($status, '✅') === 0) {
                view_list(0, $status);
            } else {
                view_add($draft, $status);
            }
            return;
        }
        $fields = add_fields();
        if (!isset($fields[$key])) {
            return;
        }
        $s = get_state();
        $draft = $s['data'] ?? add_draft();
        set_state("add:prompt:$key", $draft);
        store_view(UI_KEY, 'add', $draft);
        view_prompt('➕ TAMBAH USER — ' . $fields[$key][0], $fields[$key][1], [[btn('⬅️ Batal', 'add')]]);
        return;
    }

    // --- form edit field user lama ---
    if (strpos($data, 'ef:') === 0) {
        $parts = explode(':', $data);
        $id = (int)($parts[1] ?? 0);
        $field = (string)($parts[2] ?? '');
        $fields = edit_fields();
        if (!isset($fields[$field])) {
            return;
        }
        set_state("edit:prompt:$id:$field");
        view_prompt('✏️ EDIT — ' . $fields[$field][0], $fields[$field][1], [[btn('⬅️ Batal', "user:$id")]]);
        return;
    }

    // --- detail user & aksinya ---
    if (strpos($data, 'user:') === 0) {
        $parts = explode(':', $data);
        $id = (int)($parts[1] ?? 0);
        $action = (string)($parts[2] ?? '');

        switch ($action) {
            case '':
                clear_state();
                view_user($id);
                break;

            case 'isolir':
                clear_state();
                view_user($id, action_isolir($id));
                break;

            case 'unisolir':
                clear_state();
                view_user($id, action_unisolir($id));
                break;

            case 'toggle':
                clear_state();
                view_user($id, action_toggle_notif($id));
                break;

            case 'editmenu':
                clear_state();
                view_edit_menu($id);
                break;

            case 'perpanjang':
                set_state("perpanjang:$id");
                view_prompt('⏫ PERPANJANG', "tanggal bayar baru (YYYY-MM-DD / 'today')", [[btn('⬅️ Batal', "user:$id")]]);
                break;

            case 'editisolir':
                set_state("editisolir:$id");
                view_prompt('📅 EDIT TGL ISOLIR', "tanggal isolir baru (YYYY-MM-DD / 'today')", [[btn('⬅️ Batal', "user:$id")]]);
                break;

            case 'del':
                clear_state();
                $u = get_user($id);
                if (!$u) {
                    view_list(0, '❌ User tidak ditemukan.');
                    break;
                }
                store_view(UI_KEY, 'confirm_del', ['id' => $id]);
                ui(
                    "🗑 HAPUS USER\n\nYakin hapus {$u['nama']} ({$u['ip']})?",
                    [[btn('✅ Ya, hapus', "user:$id:delconfirm")], [btn('❌ Batal', "user:$id")]]
                );
                break;

            case 'delconfirm':
                clear_state();
                view_list(0, action_delete($id));
                break;
        }
        return;
    }
}

// ============================ INPUT TEKS ============================

function handle_message(array $msg): void
{
    $chat = (string)($msg['chat']['id'] ?? '');
    if ($chat !== ADMIN_TG_ID) {
        return;
    }

    // Pesan admin dihapus dulu supaya chat tetap bersih (hanya pesan utama tersisa).
    if (isset($msg['message_id'])) {
        tg_delete_message((int)$msg['message_id']);
    }

    $text = trim((string)($msg['text'] ?? ''));
    $s = get_state();

    // /start atau tanpa state → menu utama
    if ($s === null || $text === '' || strpos($text, '/') === 0) {
        clear_state();
        view_menu();
        return;
    }

    $state = $s['state'];
    $data  = $s['data'];

    if (is_cancel($text)) {
        clear_state();
        view_menu('❌ Dibatalkan.');
        return;
    }

    // --- draft tambah user: <field> ---
    if (strpos($state, 'add:prompt:') === 0) {
        $key = substr($state, 11);
        $fields = add_fields();
        if (!isset($fields[$key])) {
            clear_state();
            view_menu();
            return;
        }
        $draft = $data + add_draft();
        $value = $text;

        if ($key === 'ip' && !filter_var($value, FILTER_VALIDATE_IP)) {
            set_state("add:prompt:ip", $draft);
            view_prompt('➕ TAMBAH USER — IP', 'IP address yang valid (misal 192.168.3.54)', [[btn('⬅️ Batal', 'add')]]);
            return;
        }
        if ($key === 'tgl_bayar') {
            $d = parse_date($value);
            if ($d === null) {
                set_state("add:prompt:tgl_bayar", $draft);
                view_prompt('➕ TAMBAH USER — Tgl Bayar', "tanggal bayar (YYYY-MM-DD / 'today')", [[btn('⬅️ Batal', 'add')]]);
                return;
            }
            $value = $d;
        }
        if ($key === 'catatan' && ($value === '-' || $value === '')) {
            $value = '';
        }
        if (($key === 'max_upload' || $key === 'max_download') && $value === '') {
            $value = '1M';
        }

        $draft[$key] = $value;
        view_add($draft, '✔ ' . $fields[$key][0] . ' diisi.');
        return;
    }

    // --- perpanjang ---
    if (strpos($state, 'perpanjang:') === 0) {
        $id = (int)substr($state, 11);
        $d = parse_date($text);
        if ($d === null) {
            view_prompt('⏫ PERPANJANG', "tanggal bayar baru (YYYY-MM-DD / 'today')", [[btn('⬅️ Batal', "user:$id")]]);
            return;
        }
        clear_state();
        view_user($id, action_perpanjang($id, $d));
        return;
    }

    // --- edit tanggal isolir ---
    if (strpos($state, 'editisolir:') === 0) {
        $id = (int)substr($state, 11);
        $d = parse_date($text);
        if ($d === null) {
            view_prompt('📅 EDIT TGL ISOLIR', "tanggal isolir baru (YYYY-MM-DD / 'today')", [[btn('⬅️ Batal', "user:$id")]]);
            return;
        }
        clear_state();
        view_user($id, action_edit_field($id, 'tgl_isolir', $d));
        return;
    }

    // --- edit field user lama ---
    if (strpos($state, 'edit:prompt:') === 0) {
        $parts = explode(':', $state);
        $id = (int)($parts[2] ?? 0);
        $field = (string)($parts[3] ?? '');
        $val = trim($text);

        if ($field === 'ip' && !filter_var($val, FILTER_VALIDATE_IP)) {
            view_prompt('✏️ EDIT — IP', 'IP address yang valid', [[btn('⬅️ Batal', "user:$id")]]);
            return;
        }
        if ($field === 'tgl_bayar') {
            $d = parse_date($val);
            if ($d === null) {
                view_prompt('✏️ EDIT — Tgl Bayar', "tanggal bayar (YYYY-MM-DD / 'today')", [[btn('⬅️ Batal', "user:$id")]]);
                return;
            }
            $val = $d;
        }
        if ($field === 'catatan' && ($val === '-' || $val === '')) {
            $val = '';
        }

        clear_state();
        view_user($id, action_edit_field($id, $field, $val));
        return;
    }

    // fallback
    clear_state();
    view_menu();
}
