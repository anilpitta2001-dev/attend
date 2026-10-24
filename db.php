<?php
/**
 * Shared PostgreSQL database connection for the Telegram bots + attendance API.
 * Production: Supabase PostgreSQL via DATABASE_URL.
 */

date_default_timezone_set('Asia/Kolkata');

function attendance_db(): ?PDO {
    static $pdo = null;
    static $failed = false;

    if ($pdo instanceof PDO) return $pdo;
    if ($failed) return null;

    $url = trim((string)(getenv('DATABASE_URL') ?: ''));

    try {
        if ($url !== '') {
            $parts = parse_url($url);
            if ($parts === false || empty($parts['host'])) {
                throw new RuntimeException('Invalid DATABASE_URL.');
            }

            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            if (!in_array($scheme, ['postgres', 'postgresql'], true)) {
                throw new RuntimeException('DATABASE_URL must be a PostgreSQL URL.');
            }

            $host = $parts['host'];
            $port = $parts['port'] ?? 5432;
            $dbname = ltrim((string)($parts['path'] ?? ''), '/');
            $user = rawurldecode((string)($parts['user'] ?? ''));
            $password = rawurldecode((string)($parts['pass'] ?? ''));

            if ($dbname === '' || $user === '') {
                throw new RuntimeException('DATABASE_URL is missing database name or user.');
            }

            $query = [];
            if (!empty($parts['query'])) parse_str($parts['query'], $query);
            $sslmode = $query['sslmode'] ?? (getenv('DB_SSLMODE') ?: 'require');

            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $host,
                (int)$port,
                $dbname,
                $sslmode
            );

            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            $host = getenv('DB_HOST') ?: '';
            $port = (int)(getenv('DB_PORT') ?: 5432);
            $dbname = getenv('DB_NAME') ?: '';
            $user = getenv('DB_USER') ?: '';
            $password = getenv('DB_PASSWORD') ?: '';
            $sslmode = getenv('DB_SSLMODE') ?: 'require';

            if ($host === '' || $dbname === '' || $user === '') {
                throw new RuntimeException('DATABASE_URL (or DB_HOST/DB_NAME/DB_USER) is not configured.');
            }

            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $host,
                $port,
                $dbname,
                $sslmode
            );

            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        ensure_schema($pdo);
        return $pdo;
    } catch (Throwable $e) {
        $failed = true;
        error_log('PostgreSQL DB error: ' . $e->getMessage());
        return null;
    }
}

function ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        telegram_id TEXT PRIMARY KEY,
        chat_id TEXT NOT NULL,
        username TEXT,
        first_name TEXT,
        first_seen BIGINT NOT NULL,
        last_seen BIGINT NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_requests (
        id BIGSERIAL PRIMARY KEY,
        telegram_id TEXT NOT NULL REFERENCES users(telegram_id) ON DELETE CASCADE,
        roll_number TEXT NOT NULL,
        status TEXT NOT NULL,
        attendance_result TEXT,
        requested_at BIGINT NOT NULL
    )");

    $pdo->exec("ALTER TABLE attendance_requests ADD COLUMN IF NOT EXISTS check_count INTEGER NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE attendance_requests ADD COLUMN IF NOT EXISTS count_today INTEGER NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE attendance_requests ADD COLUMN IF NOT EXISTS count_date DATE NOT NULL DEFAULT CURRENT_DATE");

    // Consolidate any historical duplicate (Telegram user + roll) rows before
    // enforcing one aggregate row per pair. Keep the newest result/status.
    $pdo->exec("WITH grouped AS (
        SELECT telegram_id, roll_number, MAX(id) AS keep_id,
               SUM(GREATEST(check_count, 1)) AS total_count,
               SUM(CASE WHEN requested_at >= EXTRACT(EPOCH FROM date_trunc('day', NOW()))::BIGINT
                        THEN GREATEST(count_today, 1) ELSE 0 END) AS today_count
        FROM attendance_requests
        GROUP BY telegram_id, roll_number
        HAVING COUNT(*) > 1
    )
    UPDATE attendance_requests ar
       SET check_count = grouped.total_count,
           count_today = grouped.today_count,
           count_date = CURRENT_DATE
      FROM grouped
     WHERE ar.id = grouped.keep_id");
    $pdo->exec("WITH ranked AS (
        SELECT id, ROW_NUMBER() OVER (PARTITION BY telegram_id, roll_number ORDER BY id DESC) AS rn
        FROM attendance_requests
    ) DELETE FROM attendance_requests ar USING ranked r WHERE ar.id = r.id AND r.rn > 1");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_requests_user_roll ON attendance_requests(telegram_id, roll_number)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS suspended_roll_numbers (
        roll_number TEXT PRIMARY KEY,
        reason TEXT,
        suspended_by TEXT,
        suspended_at BIGINT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcasts (
        id BIGSERIAL PRIMARY KEY,
        message TEXT NOT NULL,
        sent_count INTEGER NOT NULL DEFAULT 0,
        failed_count INTEGER NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT,
        updated_at BIGINT NOT NULL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_last_seen ON users(last_seen)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_active ON users(is_active)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_telegram ON attendance_requests(telegram_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_requested_at ON attendance_requests(requested_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_requests_roll ON attendance_requests(roll_number)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_suspended_at ON suspended_roll_numbers(suspended_at)");
}
