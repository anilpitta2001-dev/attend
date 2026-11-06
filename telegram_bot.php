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
    // Webhook POSTs must arrive through the trusted controller and carry Telegram's secret.
    $proxyReceived = $_SERVER['HTTP_X_ATTENDANCE_PROXY_SECRET'] ?? '';
    $telegramReceived = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (BACKEND_PROXY_SECRET === '' || !hash_equals(BACKEND_PROXY_SECRET, $proxyReceived) ||
        WEBHOOK_SECRET === '' || !hash_equals(WEBHOOK_SECRET, $telegramReceived)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
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
