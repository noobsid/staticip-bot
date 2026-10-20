<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/RouterosAPI.php';

function add_days(string $date, int $days): string
{
    return date('Y-m-d', strtotime($date . " +$days days"));
}

function today(): string
{
    return date('Y-m-d');
}

function normalize_ip(string $s): string
{
    return preg_replace('#/32$#', '', trim($s));
}

function mk_connect(): RouterosAPI
{
    $api = new RouterosAPI();
    if (!$api->connect(MK_HOST, MK_USER, MK_PASS, MK_PORT)) {
        throw new RuntimeException('Gagal login MikroTik');
    }
    return $api;
}

/** Cari queue berdasarkan IP pada field target (bukan nama queue). */
function find_queue_by_ip(RouterosAPI $api, string $ip): ?array
{
    $needle = normalize_ip($ip);
    foreach ($api->getSimpleQueues() as $q) {
        $target = $q['target'] ?? '';
        if ($target === '') {
            continue;
        }
        foreach (explode(',', $target) as $t) {
            if (normalize_ip($t) === $needle) {
                return $q;
            }
        }
    }
    return null;
}

function set_queue_limit(RouterosAPI $api, string $ip, string $limit): bool
{
    $q = find_queue_by_ip($api, $ip);
    if (!$q) {
        return false;
    }
    $api->setMaxLimit($q['id'], $limit);
    return true;
}

function isolate_user(RouterosAPI $api, array $user): bool
{
    return set_queue_limit($api, $user['ip'], ISOLIR_LIMIT);
}

function restore_user(RouterosAPI $api, array $user): bool
{
    return set_queue_limit($api, $user['ip'], $user['max_upload'] . '/' . $user['max_download']);
}

/** Panggil API Telegram apa pun. Tanpa curl_close (deprecated di PHP 8.5). */
function tg_api(string $method, array $params = []): array
{
    $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/' . $method;
    if (isset($params['reply_markup']) && is_array($params['reply_markup'])) {
        $params['reply_markup'] = json_encode($params['reply_markup']);
    }
    $payload = http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 60,
        ]);
        $res = curl_exec($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 60,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
    }

    return json_decode((string)$res, true) ?: [];
}

/** Hapus satu pesan di chat admin (prompt / pesan lama). */
function tg_delete_message(int $message_id): bool
{
    if ($message_id <= 0) {
        return false;
    }
    $r = tg_api('deleteMessage', ['chat_id' => ADMIN_TG_ID, 'message_id' => $message_id]);
    return ($r['ok'] ?? false) === true;
}

function send_telegram(string $text): bool
{
    if (TG_BOT_TOKEN === '' || TG_CHAT_ID === '') {
        return false;
    }
    $r = tg_api('sendMessage', ['chat_id' => TG_CHAT_ID, 'text' => $text]);
    return ($r['ok'] ?? false) === true;
}

/**
 * Ambil notifikasi isolir yang belum dikirim hari ini (agregat, bukan spam pesan).
 * last_notif_sent langsung ditandai supaya tidak dobel di hari yang sama.
 *
 * @return array<string,string> daftar teks notifikasi per user
 */
function take_isolated_notifications(PDO $db): array
{
    $out = [];
    $rows = $db->query(
        "SELECT * FROM users
         WHERE status = 'isolir'
           AND notif_enabled = 1
           AND (last_notif_sent IS NULL OR last_notif_sent < '" . today() . "')"
    )->fetchAll();

    $mark = $db->prepare("UPDATE users SET last_notif_sent = ? WHERE id = ?");
    foreach ($rows as $u) {
        $out[] = "🔒 {$u['nama']} ({$u['ip']}) — limit " . ISOLIR_LIMIT
            . " · jt tempo {$u['tgl_isolir']}";
        $mark->execute([today(), (int)$u['id']]);
    }
    return $out;
}
