<?php
/**
 * BITS Attendance Telegram - Public User Bot
 *
 * Public bot:
 *   - Registers unique Telegram users.
 *   - Checks Roll Number suspension BEFORE calling attendance API.
 *   - Returns attendance only to the requesting chat.
 *   - Logs each lookup to the Admin Bot.
 *   - Updates the public bot display name with the unique-user count.
 */

date_default_timezone_set('Asia/Kolkata');

define('BOT_TOKEN', trim(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
define('WEBHOOK_SECRET', trim(getenv('TELEGRAM_WEBHOOK_SECRET') ?: ''));
define('ATTENDANCE_API_KEY', trim(getenv('ATTENDANCE_API_KEY') ?: ''));
define('ADMIN_CHAT_ID', trim(getenv('ADMIN_CHAT_ID') ?: ''));
define('ADMIN_BOT_TOKEN', trim(getenv('ADMIN_BOT_TOKEN') ?: ''));
define('MAINTENANCE_MODE', filter_var(getenv('MAINTENANCE_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN));

define(
    'ATTENDANCE_API_URL',
    getenv('ATTENDANCE_API_URL')
        ?: 'https://attend-ctn4.onrender.com/index.php?api=attendance'
);

function bot_db(): ?PDO { require_once __DIR__ . '/db.php'; return attendance_db(); }
function db(): ?PDO { return bot_db(); }

function telegram_request($token, $method, $params = []) {
    if ($token === '') return null;

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    return $raw ? json_decode($raw, true) : null;
}

function tg($method, $params = []) {
    return telegram_request(BOT_TOKEN, $method, $params);
}

function send_msg($chat_id, $text, $markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    if ($markup !== null) {
        $params['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
    }

    return tg('sendMessage', $params);
}

function answer_callback($id, $text = '') {
    $params = [
        'callback_query_id' => $id,
        'show_alert' => false,
    ];
    if ($text !== '') $params['text'] = $text;
    return tg('answerCallbackQuery', $params);
}

function user_info($message) {
    $user = $message['from'] ?? [];
    $id = (string)($user['id'] ?? 'unknown');

    $username = '';
    if (!empty($user['username'])) $username = '@' . $user['username'];

    $first = trim((string)($user['first_name'] ?? ''));
    $name = $first !== '' ? $first : 'Unknown user';
    if ($name === '') $name = 'Unknown user';

    return [
        'id' => $id,
        'chat_id' => (string)($message['chat']['id'] ?? $id),
        'username' => $username,
        'name' => $name,
        'first_name' => $first,
    ];
}
