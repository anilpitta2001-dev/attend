<?php
/**
 * BITS Attendance - Admin Bot
 *
 * This is the existing @stu_checked_info_bot upgraded from logger-only
 * into the private administration bot.
 *
 * Only ADMIN_IDS can use commands.
 *
 * Commands:
 *   /start
 *   /help
 *   /stats
 *   /users
 *   /suspended
 *   /suspend ROLL [reason]
 *   /unsuspend ROLL
 *   /broadcast MESSAGE
 *
 * Automatic lookup logs from the public bot continue to arrive here.
 */

date_default_timezone_set('Asia/Kolkata');

define('ADMIN_BOT_TOKEN', trim(getenv('ADMIN_BOT_TOKEN') ?: ''));
define('ADMIN_WEBHOOK_SECRET', trim(getenv('ADMIN_WEBHOOK_SECRET') ?: ''));
define('BACKEND_PROXY_SECRET', trim(getenv('BACKEND_PROXY_SECRET') ?: ''));
define('PUBLIC_BOT_TOKEN', trim(getenv('TELEGRAM_BOT_TOKEN') ?: ''));

function db(): ?PDO { require_once __DIR__ . '/db.php'; return attendance_db(); }

function telegram_request($token, $method, $params = []) {
    if ($token === '') return null;

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);
    return $raw ? json_decode($raw, true) : null;
}

function tg_admin($method, $params = []) {
    return telegram_request(ADMIN_BOT_TOKEN, $method, $params);
}

function tg_public($method, $params = []) {
    return telegram_request(PUBLIC_BOT_TOKEN, $method, $params);
}

function admin_ids(): array {
    $raw = trim(getenv('ADMIN_IDS') ?: '');
    if ($raw === '') $raw = trim(getenv('ADMIN_CHAT_ID') ?: '');

    $ids = [];
    foreach (preg_split('/[,\s]+/', $raw) as $id) {
        $id = trim($id);
        if ($id !== '') $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function is_admin($chat_id): bool {
    return in_array((string)$chat_id, admin_ids(), true);
}

function send_admin($chat_id, $text, $markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($markup !== null) {
        $params['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
    }
    return tg_admin('sendMessage', $params);
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_time($ts): string {
    return date('d M Y, h:i A', (int)$ts) . ' IST';
}

function stats_text(): string {
    $db = db();
    if (!$db) return "❌ Database unavailable.";

    $users = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $checks = (int)$db->query("SELECT COALESCE(SUM(check_count), 0) FROM attendance_requests")->fetchColumn();
    $today = (int)$db->query("SELECT COALESCE(SUM(count_today), 0) FROM attendance_requests WHERE count_date = CURRENT_DATE")->fetchColumn();
    $active = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active = TRUE AND last_seen >= " . (time() - 86400))->fetchColumn();
    $suspended = (int)$db->query("SELECT COUNT(*) FROM suspended_roll_numbers")->fetchColumn();
    $successful = (int)$db->query("SELECT COUNT(*) FROM attendance_requests WHERE status = 'success'")->fetchColumn();
    $failed = (int)$db->query("SELECT COUNT(*) FROM attendance_requests WHERE status = 'failed'")->fetchColumn();

    return
        "📊 <b>Attendance Bot Statistics</b>\n\n" .
        "👥 <b>Total Users:</b> {$users}\n" .
        "🟢 <b>Active (24h):</b> {$active}\n" .
        "🔎 <b>Total Checks:</b> {$checks}\n" .
        "📅 <b>Checks Today:</b> {$today}\n" .
        "✅ <b>Successful:</b> {$successful}\n" .
        "❌ <b>Failed:</b> {$failed}\n" .
        "🔒 <b>Suspended Rolls:</b> {$suspended}";
}

function show_users($chat_id) {
    $db = db();
    if (!$db) {
        send_admin($chat_id, "❌ Database unavailable.");
        return;
    }

    $total = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $rows = $db->query("
        SELECT telegram_id, username, first_name, first_seen, last_seen
        FROM users
        WHERE is_active = TRUE
        ORDER BY last_seen DESC
        LIMIT 30
    ")->fetchAll();

    $text = "👥 <b>Registered Users: {$total}</b>\n\n";
    if (!$rows) {
        $text .= "No users yet.";
    } else {
        $n = 1;
        foreach ($rows as $r) {
            $name = trim((string)($r['first_name'] ?? ''));
            if ($name === '') $name = 'Unknown';
            $username = $r['username'] ?: 'No username';

            $text .=
                "{$n}. <b>" . h($name) . "</b> " . h($username) . "\n" .
                "   🆔 <code>" . h($r['telegram_id']) . "</code>\n" .
                "   🕒 " . h(fmt_time($r['last_seen'])) . "\n";
            $n++;
        }

        if ($total > 30) {
            $text .= "\nShowing latest 30 users.";
        }
    }

    send_admin($chat_id, $text);
}

function show_suspended($chat_id) {
    $db = db();
    if (!$db) {
        send_admin($chat_id, "❌ Database unavailable.");
        return;
    }

    $rows = $db->query("
        SELECT roll_number, reason, suspended_by, suspended_at
        FROM suspended_roll_numbers
        ORDER BY suspended_at DESC
    ")->fetchAll();

    if (!$rows) {
        send_admin($chat_id, "🔓 <b>No suspended Roll Numbers.</b>");
        return;
    }

    $text = "🔒 <b>Suspended Roll Numbers</b>\n\n";
    foreach ($rows as $i => $r) {
        $text .=
            ($i + 1) . ". <code>" . h($r['roll_number']) . "</code>\n" .
            "   Reason: " . h($r['reason'] ?: 'Not specified') . "\n" .
            "   Since: " . h(fmt_time($r['suspended_at'])) . "\n\n";
    }

    send_admin($chat_id, $text);
}

function normalize_roll($text) {
    $roll = strtoupper(trim($text));
    return ($roll !== '' && preg_match('/^[A-Z0-9]{8,20}$/', $roll)) ? $roll : null;
}

function suspend_roll($chat_id, $roll, $reason) {
    $db = db();
    if (!$db) {
        send_admin($chat_id, "❌ Database unavailable.");
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO suspended_roll_numbers (roll_number, reason, suspended_by, suspended_at)
        VALUES (:roll, :reason, :admin, :time)
        ON CONFLICT(roll_number) DO UPDATE SET
            reason = excluded.reason,
            suspended_by = excluded.suspended_by,
            suspended_at = excluded.suspended_at
    ");

    $stmt->execute([
        ':roll' => $roll,
        ':reason' => $reason,
        ':admin' => (string)$chat_id,
        ':time' => time(),
    ]);

    send_admin(
        $chat_id,
        "🔒 <b>Roll Number Suspended</b>\n\n" .
        "🎓 <b>Roll:</b> <code>" . h($roll) . "</code>\n" .
        "📝 <b>Reason:</b> " . h($reason ?: 'Not specified') . "\n\n" .
        "The User Bot will reject this Roll Number <b>before contacting the attendance API</b>."
    );
}

function unsuspend_roll($chat_id, $roll) {
    $db = db();
    if (!$db) {
        send_admin($chat_id, "❌ Database unavailable.");
        return;
    }

    $stmt = $db->prepare("DELETE FROM suspended_roll_numbers WHERE roll_number = :roll");
    $stmt->execute([':roll' => $roll]);

    if ($stmt->rowCount() > 0) {
        send_admin($chat_id, "🔓 <b>Roll Number Restored</b>\n\n<code>" . h($roll) . "</code> can now be checked again.");
    } else {
        send_admin($chat_id, "ℹ️ <code>" . h($roll) . "</code> was not suspended.");
    }
}

function broadcast_text($admin_chat_id, $message) {
    $db = db();
    if (!$db) {
        send_admin($admin_chat_id, "❌ Database is temporarily unavailable. No broadcast was started.");
        return;
    }

    $message = trim($message);
    if ($message === '') {
        send_admin($admin_chat_id, "📢 Usage:\n<code>/broadcast Your message here</code>");
        return;
    }
    if (mb_strlen($message, 'UTF-8') > 4000) {
        send_admin($admin_chat_id, "❌ Message is too long. Telegram messages must be at most 4,000 characters.");
        return;
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS pending_broadcasts (
            token_hash TEXT PRIMARY KEY,
            admin_chat_id TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL
        )");
        // Only one pending broadcast per admin. A new draft invalidates the previous one.
        $db->prepare("DELETE FROM pending_broadcasts WHERE admin_chat_id = :chat OR expires_at < :now")
           ->execute([':chat' => (string)$admin_chat_id, ':now' => time()]);

        $token = strtoupper(bin2hex(random_bytes(4)));
        $hash = hash('sha256', $token);
        $stmt = $db->prepare("INSERT INTO pending_broadcasts
            (token_hash, admin_chat_id, message, created_at, expires_at)
            VALUES (:hash, :chat, :message, :created, :expires)");
        $stmt->execute([
            ':hash' => $hash,
            ':chat' => (string)$admin_chat_id,
            ':message' => $message,
            ':created' => time(),
            ':expires' => time() + 300,
        ]);

        $count = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active = TRUE")->fetchColumn();
        send_admin(
            $admin_chat_id,
            "🛑 <b>Broadcast NOT sent yet</b>\n\n" .
            "👥 Active recipients: <b>{$count}</b>\n" .
            "📝 Preview:\n<pre>" . h(mb_substr($message, 0, 700, 'UTF-8')) . "</pre>\n\n" .
            "To send, reply with:\n<code>/confirmbroadcast {$token}</code>\n\n" .
            "⏳ Token expires in 5 minutes and can be used once. Use <code>/cancelbroadcast</code> to discard it."
        );
    } catch (Throwable $e) {
        error_log('Unable to stage broadcast: ' . $e->getMessage());
        send_admin($admin_chat_id, "❌ Could not prepare the broadcast. No messages were sent. Check server logs.");
    }
}

function cancel_pending_broadcast($admin_chat_id) {
    $db = db();
    if (!$db) { send_admin($admin_chat_id, "❌ Database unavailable."); return; }
    $stmt = $db->prepare("DELETE FROM pending_broadcasts WHERE admin_chat_id = :chat");
    $stmt->execute([':chat' => (string)$admin_chat_id]);
    send_admin($admin_chat_id, $stmt->rowCount() ? "✅ Pending broadcast cancelled. No messages were sent." : "ℹ️ There is no pending broadcast to cancel.");
}

function confirm_broadcast($admin_chat_id, $token) {
    $db = db();
    if (!$db) { send_admin($admin_chat_id, "❌ Database unavailable. No broadcast was sent."); return; }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS pending_broadcasts (
            token_hash TEXT PRIMARY KEY,
            admin_chat_id TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL
        )");
        // Atomic DELETE ... RETURNING means the token can only be consumed once, even with concurrent requests.
        $stmt = $db->prepare("DELETE FROM pending_broadcasts
            WHERE token_hash = :hash AND admin_chat_id = :chat AND expires_at >= :now
            RETURNING message");
        $stmt->execute([
            ':hash' => hash('sha256', strtoupper(trim($token))),
            ':chat' => (string)$admin_chat_id,
            ':now' => time(),
        ]);
        $message = $stmt->fetchColumn();
        if (!is_string($message) || $message === '') {
            send_admin($admin_chat_id, "❌ Invalid, expired, or already-used confirmation token. No broadcast was sent. Run <code>/broadcast Your message</code> to create a new draft.");
            return;
        }
        send_broadcast_now($admin_chat_id, $message);
    } catch (Throwable $e) {
        error_log('Broadcast confirmation failed: ' . $e->getMessage());
        send_admin($admin_chat_id, "❌ Could not confirm the broadcast because the database operation failed. No broadcast was started.");
    }
}

function send_broadcast_now($admin_chat_id, $message) {
    $db = db();
    if (!$db) { send_admin($admin_chat_id, "❌ Database unavailable."); return; }

    $users = $db->query("SELECT telegram_id, chat_id FROM users WHERE is_active = TRUE ORDER BY telegram_id")->fetchAll();
    $total = count($users);
    if ($total === 0) { send_admin($admin_chat_id, "📢 No active registered users. Nothing was sent."); return; }

    $stmt = $db->prepare("INSERT INTO broadcasts (message, created_at, sent_count, failed_count)
        VALUES (:message, :time, 0, 0) RETURNING id");
    $stmt->execute([':message' => $message, ':time' => time()]);
    $broadcastId = (int)$stmt->fetchColumn();

    send_admin($admin_chat_id, "📢 <b>Confirmed broadcast started</b>\n\n👥 Recipients: {$total}\n⏳ Sending...");
    $sent = 0; $failed = 0;
    $deactivate = $db->prepare("UPDATE users SET is_active = FALSE WHERE telegram_id = :id");

    foreach ($users as $u) {
        $result = tg_public('sendMessage', [
            'chat_id' => $u['chat_id'],
            'text' => $message,
            'disable_web_page_preview' => true,
        ]);
        if (($result['ok'] ?? false) === true) {
            $sent++;
        } else {
            $failed++;
            $description = strtolower((string)($result['description'] ?? ''));
            if (strpos($description, 'blocked') !== false ||
                strpos($description, 'chat not found') !== false ||
                strpos($description, 'deactivated') !== false) {
                $deactivate->execute([':id' => $u['telegram_id']]);
            }
        }
        // Telegram rate limits: conservative pacing for reliability.
        usleep(35000);
    }

    $up = $db->prepare("UPDATE broadcasts SET sent_count = :sent, failed_count = :failed WHERE id = :id");
    $up->execute([':sent' => $sent, ':failed' => $failed, ':id' => $broadcastId]);
    send_admin($admin_chat_id,
        "📢 <b>Broadcast completed</b>\n\n👥 Total: {$total}\n✅ Sent: {$sent}\n❌ Failed: {$failed}\n🕒 " . h(fmt_time(time())));
}

function recent_checks($chat_id) {
    $db = db();
    if (!$db) {
        send_admin($chat_id, "❌ Database unavailable.");
        return;
    }

    $rows = $db->query("
        SELECT telegram_id, roll_number, requested_at, status, attendance_result, check_count
        FROM attendance_requests
        ORDER BY requested_at DESC
        LIMIT 20
    ")->fetchAll();

    if (!$rows) {
        send_admin($chat_id, "🔎 No attendance checks recorded yet.");
        return;
    }

    $text = "🔎 <b>Recent Attendance Checks</b>\n\n";
    foreach ($rows as $i => $r) {
        $text .=
            ($i + 1) . ". <code>" . h($r['roll_number']) . "</code> " .
            ($r['status'] === 'success' ? '✅' : '❌') . "\n" .
            "   User: <code>" . h($r['telegram_id']) . "</code>\n" .
            "   Checks by this user: <b>" . h($r['check_count'] ?? 1) . "</b>\n" .
            "   Last checked: " . h(fmt_time($r['requested_at'])) . "\n";
        if ($r['attendance_result'] !== null && $r['attendance_result'] !== '') {
            $parsed = json_decode((string)$r['attendance_result'], true);
            $attendance = is_array($parsed) ? ($parsed['attendance'] ?? null) : null;
            if ($attendance !== null && $attendance !== '') $text .= "   Attendance: " . h($attendance) . "%\n";
        }
    }

    send_admin($chat_id, $text);
}

function top_checks($chat_id) {
    $db = db();
    if (!$db) { send_admin($chat_id, "❌ Database unavailable."); return; }
    $rows = $db->query("SELECT roll_number, SUM(check_count) AS total_checks, COUNT(DISTINCT telegram_id) AS users, MAX(requested_at) AS last_checked FROM attendance_requests GROUP BY roll_number ORDER BY SUM(check_count) DESC LIMIT 10")->fetchAll();
    if (!$rows) { send_admin($chat_id, "No attendance checks recorded yet."); return; }
    $text = "🏆 <b>Top Checked Roll Numbers</b>\n\n";
    foreach ($rows as $i => $r) {
        $text .= ($i + 1) . ". <code>" . h($r['roll_number']) . "</code> — <b>" . h($r['total_checks']) . "</b> checks\n   Users: " . h($r['users']) . " | Last: " . h(fmt_time($r['last_checked'])) . "\n";
    }
    send_admin($chat_id, $text);
}

function user_roll_checks($chat_id, $roll) {
    $db = db();
    if (!$db) { send_admin($chat_id, "❌ Database unavailable."); return; }
    $stmt = $db->prepare("SELECT ar.telegram_id, ar.check_count, ar.requested_at, u.username, u.first_name FROM attendance_requests ar LEFT JOIN users u ON u.telegram_id = ar.telegram_id WHERE ar.roll_number = :roll ORDER BY ar.check_count DESC LIMIT 20");
    $stmt->execute([':roll' => $roll]);
    $rows = $stmt->fetchAll();
    if (!$rows) { send_admin($chat_id, "No checks found for <code>" . h($roll) . "</code>."); return; }
    $text = "🔍 <b>Users checking <code>" . h($roll) . "</code></b>\n\n";
    foreach ($rows as $i => $r) {
        $name = trim((string)($r['first_name'] ?? ''));
        if ($name === '') $name = $r['username'] ? '@' . $r['username'] : 'Unknown';
        $text .= ($i + 1) . ". " . h($name) . " — <b>" . h($r['check_count']) . " checks</b>\n   Telegram ID: <code>" . h($r['telegram_id']) . "</code>\n   Last: " . h(fmt_time($r['requested_at'])) . "\n";
    }
    send_admin($chat_id, $text);
}

function admin_help($chat_id) {
    send_admin(
        $chat_id,
        "👑 <b>Attendance Admin Bot</b>\n\n" .
        "📊 <code>/stats</code> — users/check statistics\n" .
        "👥 <code>/users</code> — latest registered users\n" .
        "🔎 <code>/checks</code> — recent attendance checks\n" .
        "🏆 <code>/topchecks</code> — most checked roll numbers\n" .
        "👤 <code>/userchecks ROLL</code> — users checking a roll\n" .
        "🔒 <code>/suspend ROLL reason</code> — suspend a Roll Number\n" .
        "🔓 <code>/unsuspend ROLL</code> — restore a Roll Number\n" .
        "📋 <code>/suspended</code> — list suspended Rolls\n" .
        "📢 <code>/broadcast MESSAGE</code> — prepare a broadcast (does not send)\n" .
        "✅ <code>/confirmbroadcast TOKEN</code> — confirm a pending broadcast\n" .
        "🛑 <code>/cancelbroadcast</code> — cancel a pending broadcast\n" .
        "💬 <code>/supportid TELEGRAM_ID MESSAGE</code> — reply to a user support request\n\n" .
        "🔐 Only authorized admin Telegram IDs can use these commands."
    );
}

function handle_admin_message($message) {
    $chat_id = (string)($message['chat']['id'] ?? '');
    $text = trim($message['text'] ?? '');

    if (!is_admin($chat_id)) {
        if ($text !== '') {
            send_admin($chat_id, "⛔ <b>Unauthorized.</b>\n\nThis bot is restricted to the attendance administrator.");
        }
        return;
    }

    if ($text === '') return;

    if (preg_match('/^\/start\b/i', $text) || preg_match('/^\/help\b/i', $text)) {
        admin_help($chat_id);
        return;
    }

    if (preg_match('/^\/stats\b/i', $text)) {
        send_admin($chat_id, stats_text());
        return;
    }

    if (preg_match('/^\/users\b/i', $text)) {
        show_users($chat_id);
        return;
    }

    if (preg_match('/^\/checks\b/i', $text)) {
        recent_checks($chat_id);
        return;
    }

    if (preg_match('/^\/topchecks\b/i', $text)) {
        top_checks($chat_id);
        return;
    }

    if (preg_match('/^\/userchecks(?:@\w+)?\s+([A-Za-z0-9]{8,20})$/i', $text, $m)) {
        $roll = normalize_roll($m[1]);
        if ($roll === null) { send_admin($chat_id, "❌ Invalid Roll Number."); return; }
        user_roll_checks($chat_id, $roll);
        return;
    }

    if (preg_match('/^\/suspended\b/i', $text)) {
        show_suspended($chat_id);
        return;
    }

    if (preg_match('/^\/suspend(?:@\w+)?\s+([A-Za-z0-9]{8,20})(?:\s+(.+))?$/is', $text, $m)) {
        $roll = normalize_roll($m[1]);
        $reason = trim($m[2] ?? '');

        if ($roll === null) {
            send_admin($chat_id, "❌ Invalid Roll Number.");
            return;
        }

        suspend_roll($chat_id, $roll, $reason);
        return;
    }

    if (preg_match('/^\/unsuspend(?:@\w+)?\s+([A-Za-z0-9]{8,20})$/i', $text, $m)) {
        $roll = normalize_roll($m[1]);

        if ($roll === null) {
            send_admin($chat_id, "❌ Invalid Roll Number.");
            return;
        }

        unsuspend_roll($chat_id, $roll);
        return;
    }

    if (preg_match('/^\/supportid(?:@\w+)?\s+(\d+)\s+([\s\S]+)$/i', $text, $m)) {
        $targetId = $m[1]; $replyText = trim($m[2]);
        $sent = tg_public('sendMessage', ['chat_id' => $targetId, 'text' => $replyText, 'disable_web_page_preview' => true]);
        if (is_array($sent) && !empty($sent['ok'])) send_admin($chat_id, "✅ Support reply sent to <code>" . h($targetId) . "</code>.");
        else {
            $description = is_array($sent) ? ($sent['description'] ?? 'Unknown Telegram error') : 'Public bot API did not respond';
            send_admin($chat_id, "❌ Could not send to <code>" . h($targetId) . "</code>. " . h($description) . "\nMake sure the user has started the public bot.");
        }
        return;
    }

    if (preg_match('/^\/confirmbroadcast(?:@\w+)?\s+([A-F0-9]{8})$/i', $text, $m)) {
        confirm_broadcast($chat_id, $m[1]);
        return;
    }

    if (preg_match('/^\/cancelbroadcast(?:@\w+)?$/i', $text)) {
        cancel_pending_broadcast($chat_id);
        return;
    }

    if (preg_match('/^\/broadcast(?:@\w+)?(?:\s+([\s\S]+))?$/i', $text, $m)) {
        $message_text = trim($m[1] ?? '');
        broadcast_text($chat_id, $message_text);
        return;
    }

    send_admin(
        $chat_id,
        "❓ Unknown admin command.\n\nUse <code>/help</code>."
    );
}

/* Admin webhook security */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Webhook POSTs must arrive through the trusted controller and carry Telegram's secret.
    $proxyReceived = $_SERVER['HTTP_X_ATTENDANCE_PROXY_SECRET'] ?? '';
    $telegramReceived = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (BACKEND_PROXY_SECRET === '' || !hash_equals(BACKEND_PROXY_SECRET, $proxyReceived) ||
        ADMIN_WEBHOOK_SECRET === '' || !hash_equals(ADMIN_WEBHOOK_SECRET, $telegramReceived)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $raw = file_get_contents('php://input');
    $update = json_decode($raw, true);

    // Telegram retries webhook updates when a handler runs too long (broadcasts can
    // take several seconds). Claim each update_id in PostgreSQL before handling it,
    // so a retry cannot trigger the same broadcast or command twice.
    if (is_array($update) && isset($update['update_id'])) {
        $pdo = db();
        if ($pdo) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_admin_updates (
                    update_id BIGINT PRIMARY KEY,
                    received_at BIGINT NOT NULL
                )");
                $claim = $pdo->prepare(
                    "INSERT INTO telegram_admin_updates (update_id, received_at)
                     VALUES (:id, :received) ON CONFLICT (update_id) DO NOTHING"
                );
                $claim->execute([':id' => (int)$update['update_id'], ':received' => time()]);
                if ($claim->rowCount() === 0) {
                    http_response_code(200);
                    echo 'Duplicate update ignored';
                    exit;
                }
            } catch (Throwable $e) {
                // Fail closed for commands that can cause side effects.
                http_response_code(503);
                echo 'Update deduplication unavailable';
                exit;
            }
        } else {
            // Avoid executing a potentially repeated broadcast if the database is down.
            http_response_code(503);
            echo 'Database unavailable';
            exit;
        }
    }

    if (is_array($update) && isset($update['message'])) {
        handle_admin_message($update['message']);
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "BITS Attendance Admin Bot is running.\n";
