<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

define('CRON_SECRET', getenv('CRON_SECRET') ?: '');
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_CHAT_ID', getenv('TELEGRAM_CHAT_ID') ?: '');
define('ATTENDANCE_ROLL', strtoupper(trim(getenv('ATTENDANCE_ROLL') ?: '')));
define('ATTENDANCE_API_URL', getenv('ATTENDANCE_API_URL') ?: 'https://attendancechecker-o41l.onrender.com/index.php?api=attendance');
define('ATTENDANCE_API_KEY', getenv('ATTENDANCE_API_KEY') ?: '');

$key = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
if (CRON_SECRET === '' || !hash_equals(CRON_SECRET, (string)$key)) {
    http_response_code(403);
    exit('Forbidden');
}
if (TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '' || ATTENDANCE_ROLL === '') {
    http_response_code(500);
    exit('Telegram/attendance environment variables are incomplete.');
}

$ch = curl_init(ATTENDANCE_API_URL);
$headers = ['Accept: application/json'];
if (ATTENDANCE_API_KEY !== '') $headers[] = 'X-API-Key: ' . ATTENDANCE_API_KEY;
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['roll' => ATTENDANCE_ROLL]),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

$data = json_decode((string)$raw, true);
if (!is_array($data) || empty($data['ok'])) {
    http_response_code(502);
    exit('Attendance fetch failed: '.($data['error'] ?? $err ?: 'unknown error'));
}

$d = $data['data'];
$num = is_numeric($d['attendance'] ?? null) ? (float)$d['attendance'] : -1;
$icon = $num >= 75 ? '🟢' : '🔴';
$warning = $num >= 0 && $num < 75 ? "\n⚠️ <b>Below 75%</b>" : '';
$text = "🔔 <b>Scheduled Attendance Update</b>\n\n"
    ."👤 <b>".htmlspecialchars((string)($d['name'] ?? ''), ENT_QUOTES, 'UTF-8')."</b>\n"
    ."🎓 <code>".htmlspecialchars((string)($d['roll'] ?? ATTENDANCE_ROLL), ENT_QUOTES, 'UTF-8')."</code>\n"
    ."{$icon} <b>Attendance:</b> ".htmlspecialchars((string)($d['attendance'] ?? 'Not found'), ENT_QUOTES, 'UTF-8')."%\n"
    ."📅 <b>Last Attended:</b> ".htmlspecialchars((string)($d['last_date'] ?? 'Not found'), ENT_QUOTES, 'UTF-8').$warning;

$tg = curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/sendMessage');
curl_setopt_array($tg, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true'
    ],
    CURLOPT_TIMEOUT => 30,
]);
$out = curl_exec($tg);
curl_close($tg);

echo "OK\n";
