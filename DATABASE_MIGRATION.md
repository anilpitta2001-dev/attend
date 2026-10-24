# Database Migration

This release stores one aggregate row per `(telegram_id, roll_number)` in `attendance_requests` instead of adding a new row on every repeated check. `check_count` tracks all checks, while `count_today` and `count_date` support today's statistics. The most recent status/result/time are retained.

## Existing production database

1. Back up the Supabase database first.
2. Deploy this version. `db.php` adds the new columns, consolidates existing duplicate rows per Telegram user + roll number (preserving total counts and the latest row), and creates a unique index. This runs automatically on the first successful DB connection.
3. Verify `/stats`, `/topchecks`, `/userchecks ROLL`, and `/checks` in the private admin bot.

The existing `users`, suspension, broadcasts, and bot settings tables are unchanged. This intentionally keeps aggregate counts by Telegram user and roll number, so admins can identify which Telegram accounts are checking a roll repeatedly.
