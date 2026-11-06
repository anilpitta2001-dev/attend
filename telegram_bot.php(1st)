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

function register_user($message): void {
    $db = db();
    if (!$db) return;

    $u = user_info($message);
    $now = time();

    $stmt = $db->prepare("
        INSERT INTO users
            (telegram_id, chat_id, username, first_name, first_seen, last_seen, is_active)
        VALUES
            (:id, :chat, :username, :first, :first_seen, :last_seen, TRUE)
        ON CONFLICT(telegram_id) DO UPDATE SET
            chat_id = EXCLUDED.chat_id,
            username = EXCLUDED.username,
            first_name = EXCLUDED.first_name,
            last_seen = EXCLUDED.last_seen,
            is_active = TRUE
    ");

    $stmt->execute([
        ':id' => $u['id'],
        ':chat' => $u['chat_id'],
        ':username' => $u['username'],
        ':first' => $u['first_name'],
        ':first_seen' => $now,
        ':last_seen' => $now,
    ]);

    update_public_bot_name();
}

function unique_user_count(): int {
    $db = db();
    if (!$db) return 0;
    return (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
}

function update_public_bot_name(): void {
    $db = db();
    if (!$db) return;

    $count = unique_user_count();
    if ($count < 1) return;

    // Throttle Bot API profile-name updates to avoid rate limits.
    $stmt = $db->prepare("SELECT setting_value, updated_at FROM bot_settings WHERE setting_key = 'public_user_count_name' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();

    $previous = $row ? (int)$row['setting_value'] : null;
    $updatedAt = $row ? (int)$row['updated_at'] : 0;
    if ($previous === $count && (time() - $updatedAt) < 900) return;
    if ($previous !== null && $previous !== $count && (time() - $updatedAt) < 60) return;

    $result = telegram_request(BOT_TOKEN, 'setMyName', [
        'name' => 'Attendance Checker • 👥 ' . $count . ' Users',
    ]);

    if (($result['ok'] ?? false) === true) {
        $up = $db->prepare("
            INSERT INTO bot_settings (setting_key, setting_value, updated_at)
            VALUES ('public_user_count_name', :value, :time)
            ON CONFLICT(setting_key) DO UPDATE SET
                setting_value = EXCLUDED.setting_value,
                updated_at = EXCLUDED.updated_at
        ");
        $up->execute([':value' => (string)$count, ':time' => time()]);
    }
}

function roll_status($roll, &$reason = null): string {
    $db = db();
    if (!$db) {
        $reason = 'Database connection unavailable.';
        return 'db_error';
    }

    $stmt = $db->prepare("SELECT reason FROM suspended_roll_numbers WHERE roll_number = :roll LIMIT 1");
    $stmt->execute([':roll' => $roll]);
    $row = $stmt->fetch();

    if ($row) {
        $reason = (string)($row['reason'] ?? '');
        return 'suspended';
    }
    return 'ok';
}

function is_roll_suspended($roll, &$reason = null): bool {
    return roll_status($roll, $reason) === 'suspended';
}

function record_request($message, $roll, $result, $source): void {
    $db = db();
    if (!$db) return;

    $u = user_info($message);
    $ok = (($result['ok'] ?? false) === true);
    $data = $result['data'] ?? [];
    $attendanceResult = $ok
        ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : (string)($result['error'] ?? '');

    $stmt = $db->prepare("
        INSERT INTO attendance_requests
        (telegram_id, roll_number, status, attendance_result, requested_at, check_count, count_today, count_date)
        VALUES (:telegram_id, :roll, :status, :attendance_result, :requested_at, 1, 1, CURRENT_DATE)
        ON CONFLICT (telegram_id, roll_number) DO UPDATE SET
            status = EXCLUDED.status,
            attendance_result = EXCLUDED.attendance_result,
            requested_at = EXCLUDED.requested_at,
            check_count = attendance_requests.check_count + 1,
            count_today = CASE
                WHEN attendance_requests.count_date = CURRENT_DATE THEN attendance_requests.count_today + 1
                ELSE 1
            END,
            count_date = CURRENT_DATE
    ");

    $stmt->execute([
        ':telegram_id' => $u['id'],
        ':roll' => $roll,
        ':status' => $ok ? 'success' : 'failed',
        ':attendance_result' => $attendanceResult,
        ':requested_at' => time(),
    ]);
}

function admin_log($text) {
    if (ADMIN_BOT_TOKEN === '' || ADMIN_CHAT_ID === '') return;

    telegram_request(ADMIN_BOT_TOKEN, 'sendMessage', [
        'chat_id' => ADMIN_CHAT_ID,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
}

function log_lookup($message, $roll, $result, $source) {
    $user = user_info($message);

    $name = htmlspecialchars($user['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $username = htmlspecialchars($user['username'] ?: 'No username', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $telegram_id = htmlspecialchars($user['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe_roll = htmlspecialchars($roll, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe_source = htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $time = date('Y-m-d H:i:s') . ' IST';

    if (($result['ok'] ?? false) === true) {
        $data = $result['data'] ?? [];
        $attendance = htmlspecialchars((string)($data['attendance'] ?? 'Not found'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $student_name = htmlspecialchars((string)($data['name'] ?? 'Unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $branch = htmlspecialchars((string)($data['branch'] ?? 'Not available'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        admin_log(
            "📋 <b>Attendance Lookup</b>\n\n" .
            "🕒 <b>Time:</b> {$time}\n" .
            "👤 <b>Telegram User:</b> {$name}\n" .
            "🔹 <b>Username:</b> {$username}\n" .
            "🆔 <b>Telegram ID:</b> <code>{$telegram_id}</code>\n" .
            "🎓 <b>Roll Checked:</b> <code>{$safe_roll}</code>\n" .
            "📊 <b>Attendance:</b> {$attendance}%\n" .
            "🏫 <b>Branch:</b> {$branch}\n" .
            "📚 <b>Portal Name:</b> {$student_name}\n" .
            "⚙️ <b>Source:</b> {$safe_source}"
        );
        return;
    }

    $error = htmlspecialchars((string)($result['error'] ?? 'Unknown error'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    admin_log(
        "⚠️ <b>Attendance Lookup Failed</b>\n\n" .
        "🕒 <b>Time:</b> {$time}\n" .
        "👤 <b>Telegram User:</b> {$name}\n" .
        "🔹 <b>Username:</b> {$username}\n" .
        "🆔 <b>Telegram ID:</b> <code>{$telegram_id}</code>\n" .
        "🎓 <b>Roll Attempted:</b> <code>{$safe_roll}</code>\n" .
        "❌ <b>Error:</b> {$error}\n" .
        "⚙️ <b>Source:</b> {$safe_source}"
    );
}

function normalize_roll($text) {
    $roll = strtoupper(trim($text));
    if ($roll === '' || !preg_match('/^[A-Z0-9]{8,20}$/', $roll)) return null;
    return $roll;
}

function fetch_attendance($roll) {
    if (MAINTENANCE_MODE) {
        return [
            'ok' => false,
            'maintenance' => true,
            'error' => '🛠️ Attendance service is temporarily under maintenance. The developer is working on it; please try again shortly.'
        ];
    }

    $ch = curl_init(ATTENDANCE_API_URL);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ];
    if (ATTENDANCE_API_KEY !== '') $headers[] = 'X-API-Key: ' . ATTENDANCE_API_KEY;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['roll' => $roll]),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 22,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErrNo !== 0 || $raw === false || $http === 0) {
        error_log('Attendance API transport failure: curl_errno=' . $curlErrNo . ' error=' . $curlErr);
        return [
            'ok' => false,
            'maintenance' => true,
            'error' => '⚠️ The attendance service could not be reached right now. This may be a temporary server/network issue. Please try again in a few minutes.'
        ];
    }

    $data = json_decode((string)$raw, true);

    // A platform error page (for example HTTP 421/502) is not the API's JSON response.
    if (!is_array($data)) {
        error_log('Attendance API returned non-JSON response; HTTP=' . $http);
        if ($http === 401 || $http === 403) {
            return ['ok' => false, 'error' => '⚙️ The attendance service configuration is invalid. The developer needs to check the API key or access settings.'];
        }
        if ($http === 421 || $http === 404) {
            return ['ok' => false, 'maintenance' => true, 'error' => '⚠️ The attendance API endpoint is misconfigured or temporarily unavailable. Please try later; the developer needs to check the deployed API URL.'];
        }
        if ($http >= 500) {
            return ['ok' => false, 'maintenance' => true, 'error' => '⚠️ The attendance service is temporarily unavailable. Please try again in a few minutes.'];
        }
        return ['ok' => false, 'error' => '⚠️ The attendance service returned an unexpected response. Please try again later.'];
    }

    if (($data['ok'] ?? false) !== true) {
        $apiError = trim((string)($data['error'] ?? ''));
        $code = (int)($data['code'] ?? $http);

        if ($http === 401 || $http === 403) {
            return ['ok' => false, 'error' => '⚙️ The attendance service rejected its API credentials or access. The developer needs to check the Render environment settings.'];
        }
        if ($http === 429 || $code === 429) {
            return ['ok' => false, 'error' => '⏳ Too many requests for this roll number. Please wait 10 seconds before trying again.'];
        }
        if ($http === 404 || $code === 404) {
            return ['ok' => false, 'error' => '❌ No student record was found for that roll number. Please check it and try again.'];
        }
        if ($http >= 500 || $code >= 500) {
            // Keep actionable API error detail if it is a known, user-safe message.
            $safeErrors = [
                'The college portal or its connection is temporarily unavailable. Please try again in a few minutes.',
                'The attendance service could not sign in to the college portal. Please try again later; if the issue continues, contact the developer.',
                'Your portal session expired and the service could not sign in again. Please try later or contact the developer.',
                'The college portal did not return the student details. This may be a temporary portal/proxy issue; please retry in a few minutes.',
                'The portal opened, but its attendance data could not be read. The portal layout may have changed; please try later.',
                'The college portal returned an unexpected response. The developer has been notified in server logs; please try again later.',
                'The attendance service is not configured correctly. The developer needs to check the college portal login settings.',
                'The attendance service proxy is not configured correctly. The developer needs to check the proxy settings.',
                'Attendance service is temporarily under maintenance. Please try again shortly.'
            ];
            if ($apiError !== '' && in_array($apiError, $safeErrors, true)) {
                return ['ok' => false, 'maintenance' => true, 'error' => '⚠️ ' . $apiError];
            }
            return ['ok' => false, 'maintenance' => true, 'error' => '⚠️ The attendance service encountered a server-side error. Please try again shortly; the developer should check the service logs.'];
        }

        if ($apiError !== '') {
            // Known validation/suspension messages are useful to the user; do not expose arbitrary server output.
            if (stripos($apiError, 'suspended') !== false) {
                return ['ok' => false, 'error' => '🚫 This roll number is currently suspended from attendance lookups. Please contact the administrator if you believe this is a mistake.'];
            }
            if (stripos($apiError, 'Invalid roll number') !== false) {
                return ['ok' => false, 'error' => '❌ That roll number format is invalid. Please check it and send the correct roll number.'];
            }
            if (stripos($apiError, 'Database temporarily unavailable') !== false) {
                return ['ok' => false, 'maintenance' => true, 'error' => '⚠️ The attendance service database is temporarily unavailable. Please try again later.'];
            }
        }
        return ['ok' => false, 'error' => '⚠️ The attendance request could not be completed (HTTP ' . $http . '). Please check the roll number and try again; if it continues, contact support.'];
    }

    return ['ok' => true, 'data' => $data['data'] ?? []];
}

function attendance_text($data) {
    $name = htmlspecialchars((string)($data['name'] ?? 'Student'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $roll = htmlspecialchars((string)($data['roll'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $branch = htmlspecialchars((string)($data['branch'] ?? 'Not available'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $attendance_raw = $data['attendance'] ?? null;
    $attendance = htmlspecialchars((string)($attendance_raw ?? 'Not found'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $last = htmlspecialchars((string)($data['last_date'] ?? 'Not found'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $number = is_numeric($attendance_raw) ? (float)$attendance_raw : null;
    $emoji = $number === null ? 'ℹ️' : ($number >= 75 ? '🟢' : '🔴');

    return
        "📊 <b>BITS Attendance</b>\n\n" .
        "👤 <b>Name:</b> {$name}\n" .
        "🎓 <b>Roll:</b> <code>{$roll}</code>\n" .
        "🏫 <b>Branch:</b> {$branch}\n\n" .
        "{$emoji} <b>Attendance:</b> {$attendance}%\n" .
        "📅 <b>Last Attended:</b> {$last}\n\n" .
        "⚠️ Be Safe\n" .
        "If you check another Roll Number, wait 10 seconds for better efficiency.";
}

function report_keyboard($roll) {
    $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($roll));

    return [
        'inline_keyboard' => [
            [[
                'text' => '🔄 Refresh Attendance',
                'callback_data' => 'refresh|' . $safe,
            ]],
            [[
                'text' => '🔁 Change Roll Number',
                'callback_data' => 'change_roll',
            ]],
        ],
    ];
}

function process_roll($chat_id, $roll, $message_context = null, $source = 'message') {
    // CRITICAL: suspension check happens BEFORE any attendance API request.
    $reason = '';
    $rollStatus = roll_status($roll, $reason);
    if ($rollStatus === 'db_error') {
        send_msg($chat_id, "⚠️ <b>Service temporarily unavailable</b>\n\nThe database connection is unavailable. Please try again shortly.");
        return;
    }

    if ($rollStatus === 'suspended') {
        $message = "🔒 <b>Roll Number Suspended</b>\n\n" .
                   "The Roll Number <code>" . htmlspecialchars($roll, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                   "</code> is temporarily unavailable.\n\n" .
                   "Please contact the administrator if you believe this is an error.";

        send_msg($chat_id, $message);

        if (is_array($message_context)) {
            record_request($message_context, $roll, [
                'ok' => false,
                'error' => 'Roll Number suspended' . ($reason !== '' ? ': ' . $reason : ''),
            ], 'suspended_' . $source);

            log_lookup($message_context, $roll, [
                'ok' => false,
                'error' => 'Roll Number suspended' . ($reason !== '' ? ': ' . $reason : ''),
            ], 'suspended_' . $source);
        }
        return;
    }

    $result = fetch_attendance($roll);

    if (is_array($message_context)) {
        record_request($message_context, $roll, $result, $source);
        log_lookup($message_context, $roll, $result, $source);
    }

    if (($result['ok'] ?? false) !== true) {
        send_msg(
            $chat_id,
            (($result['maintenance'] ?? false) === true
                ? "⚠️ <b>Service temporarily unavailable</b>\n\nAttendance server is unreachable. Developer enabled maintenance mode; we will be back soon."
                : "❌ <b>Attendance fetch failed</b>\n\n" .
                  htmlspecialchars((string)$result['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                  "\n\nPlease check the roll number and try again.")
        );
        return;
    }

    send_msg($chat_id, attendance_text($result['data']), report_keyboard($roll));
}

function handle_message($message) {
    $chat_id = $message['chat']['id'];
    $text = trim($message['text'] ?? '');

    if ($text === '') return;

    register_user($message);

    // Support requests: /support notifies the admin; /support <message> forwards details.
    if (preg_match('/^\/support(?:@\w+)?(?:\s+([\s\S]+))?$/i', $text, $supportMatch)) {
        $u = user_info($message);
        $supportText = trim($supportMatch[1] ?? '');
        $identity = "👤 <b>Support request</b>\n"
            . "Name: " . htmlspecialchars($u['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
            . "Username: " . htmlspecialchars($u['username'] !== '' ? $u['username'] : 'Not set', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
            . "Telegram ID: <code>" . htmlspecialchars($u['id'], ENT_QUOTES, 'UTF-8') . "</code>\n";
        if (ADMIN_BOT_TOKEN !== '' && ADMIN_CHAT_ID !== '') {
            $adminText = $identity . ($supportText !== '' ? "\n💬 <b>Message:</b>\n" . htmlspecialchars($supportText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : "\nThey used /support and are requesting contact.");
            telegram_request(ADMIN_BOT_TOKEN, 'sendMessage', [
                'chat_id' => ADMIN_CHAT_ID, 'text' => $adminText, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
            ]);
        }
        send_msg($chat_id, $supportText !== ''
            ? "✅ Your support message has been sent to the administrator. They can reply to you here."
            : "💬 <b>Contact support</b>\n\nSend your message using:\n<code>/support Your message here</code>\n\nYour Telegram ID will be shared with the administrator so they can reply.");
        return;
    }

    if (preg_match('/^\/start\b/i', $text)) {
        send_msg(
            $chat_id,
            "🤖 <b>BITS Attendance Bot</b>\n\n" .
            "Send your college roll number to check live attendance.\n\n" .
            "🎓 Example: <code>24C31AXXXX</code>\n\n" .
            "You can also use:\n" .
            "<code>/attendance 24C31AXXXX</code>\n\n" .
            "🔐 <b>Privacy:</b> Your Telegram ID and submitted Roll Number " .
            "are used for service/security logging. Other users cannot see " .
            "your requests or attendance results."
        );
        return;
    }

    if (preg_match('/^\/privacy\b/i', $text)) {
        send_msg(
            $chat_id,
            "🔐 <b>Privacy</b>\n\n" .
            "The service records your Telegram ID, username when available, " .
            "submitted Roll Number, time, and lookup status for service/security " .
            "analytics. These records are private to the service administrator."
        );
        return;
    }

    if (preg_match('/^\/help\b/i', $text)) {
        send_msg(
            $chat_id,
            "ℹ️ <b>How to use</b>\n\n" .
            "Send your Roll Number directly.\n" .
            "Example: <code>24C31AXXXX</code>\n\n" .
            "Or use <code>/attendance 24C31AXXXX</code>.\n\n" .
            "🔄 Refresh for the latest result.\n" .
            "🔁 Change Roll Number to check another roll."
        );
        return;
    }

    if (preg_match('/^\/attendance(?:@\w+)?\s+([A-Za-z0-9]{8,20})$/i', $text, $match)) {
        $roll = normalize_roll($match[1]);

        if ($roll === null) {
            send_msg($chat_id, "❌ Invalid Roll Number.");
            return;
        }

        $reason = '';
        $rollStatus = roll_status($roll, $reason);
        if ($rollStatus === 'db_error') {
            send_msg($chat_id, "⚠️ <b>Service temporarily unavailable</b>\n\nPlease try again shortly.");
            return;
        }
        if ($rollStatus === 'suspended') {
            send_msg($chat_id, "🔒 <b>Roll Number Suspended</b>\n\nThis Roll Number is temporarily unavailable.");
            record_request($message, $roll, ['ok' => false, 'error' => 'Roll Number suspended'], 'suspended_command');
            return;
        }

        send_msg($chat_id, "⏳ Fetching live attendance for <code>{$roll}</code>...");
        process_roll($chat_id, $roll, $message, 'command');
        return;
    }

    if (preg_match('/^\/attendance(?:@\w+)?\s*$/i', $text)) {
        send_msg($chat_id, "🎓 Please send your Roll Number.\nExample: <code>24C31AXXXX</code>");
        return;
    }

    $roll = normalize_roll($text);

    if ($roll !== null) {
        $reason = '';
        $rollStatus = roll_status($roll, $reason);
        if ($rollStatus === 'db_error') {
            send_msg($chat_id, "⚠️ <b>Service temporarily unavailable</b>\n\nPlease try again shortly.");
            return;
        }
        if ($rollStatus === 'suspended') {
            send_msg($chat_id, "🔒 <b>Roll Number Suspended</b>\n\nThis Roll Number is temporarily unavailable.");
            record_request($message, $roll, ['ok' => false, 'error' => 'Roll Number suspended'], 'suspended_direct_roll');
            return;
        }

        send_msg($chat_id, "⏳ Fetching live attendance for <code>{$roll}</code>...");
        process_roll($chat_id, $roll, $message, 'direct_roll');
        return;
    }

    send_msg(
        $chat_id,
        "❓ I didn't recognize that input.\n\n" .
        "Please send your Roll Number, for example:\n" .
        "<code>24C31AXXXX</code>\n\n" .
        "Or use <code>/help</code>."
    );
}

function handle_callback($callback) {
    $callback_id = $callback['id'] ?? '';
    $data = $callback['data'] ?? '';
    $message = $callback['message'] ?? null;

    if ($callback_id !== '') answer_callback($callback_id);
    if (!$message) return;

    // Callback updates put the real user in callback['from']; message['from'] is the bot.
    $message['from'] = $callback['from'] ?? ($message['from'] ?? []);
    register_user($message);
    $chat_id = $message['chat']['id'];

    if (strpos($data, 'refresh|') === 0) {
        $roll = normalize_roll(substr($data, 8));

        if ($roll === null) {
            send_msg($chat_id, "❌ Roll Number is invalid. Please enter it again.");
            return;
        }

        $reason = '';
        $rollStatus = roll_status($roll, $reason);
        if ($rollStatus === 'db_error') {
            send_msg($chat_id, "⚠️ <b>Service temporarily unavailable</b>\n\nPlease try again shortly.");
            return;
        }
        if ($rollStatus === 'suspended') {
            send_msg($chat_id, "🔒 <b>Roll Number Suspended</b>\n\nThis Roll Number is temporarily unavailable.");
            record_request($message, $roll, ['ok' => false, 'error' => 'Roll Number suspended'], 'suspended_refresh');
            return;
        }

        send_msg($chat_id, "⏳ Refreshing live attendance for <code>{$roll}</code>...");
        process_roll($chat_id, $roll, $message, 'refresh_button');
        return;
    }

    if ($data === 'change_roll') {
        send_msg($chat_id, "🔁 <b>Change Roll Number</b>\n\nSend the new Roll Number.\nExample: <code>24C31AXXXX</code>");
    }
}

/* Telegram webhook security */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (WEBHOOK_SECRET !== '') {
        $received = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        if (!hash_equals(WEBHOOK_SECRET, $received)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    $raw = file_get_contents('php://input');
    $update = json_decode($raw, true);

    // Deduplicate Telegram webhook retries before executing side effects.
    if (is_array($update) && isset($update['update_id'])) {
        $pdo = db();
        if (!$pdo) { http_response_code(503); echo 'Database unavailable'; exit; }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_public_updates (update_id BIGINT PRIMARY KEY, received_at BIGINT NOT NULL)");
            $claim = $pdo->prepare("INSERT INTO telegram_public_updates (update_id, received_at) VALUES (:id, :received) ON CONFLICT (update_id) DO NOTHING");
            $claim->execute([':id' => (int)$update['update_id'], ':received' => time()]);
            if ($claim->rowCount() === 0) { http_response_code(200); echo 'Duplicate update ignored'; exit; }
        } catch (Throwable $e) { http_response_code(503); echo 'Update deduplication unavailable'; exit; }
    }

    if (is_array($update)) {
        if (isset($update['message'])) handle_message($update['message']);
        elseif (isset($update['callback_query'])) handle_callback($update['callback_query']);
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "BITS Attendance Telegram User Bot is running.\n";
