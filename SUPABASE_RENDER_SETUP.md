# Supabase + Render Setup

This version uses **Supabase PostgreSQL** as the shared persistent database for:

- `@live_attendance_checker_bot` — public/user bot
- `@stu_checked_info_bot` — private admin bot
- `index.php` — attendance API suspension guard

The three components use the same PostgreSQL database.

## Database tables

The application uses exactly these core tables:

```text
users
├── telegram_id
├── chat_id
├── username
├── first_name
├── first_seen
├── last_seen
└── is_active

attendance_requests
├── telegram_id
├── roll_number
├── status
├── attendance_result
└── requested_at

suspended_roll_numbers
├── roll_number
├── reason
├── suspended_by
└── suspended_at

broadcasts
├── message
├── sent_count
├── failed_count
└── created_at

bot_settings
└── public_user_count/name update settings
```

`attendance_requests.id` and `broadcasts.id` are internal PostgreSQL IDs. `bot_settings` stores the public bot name/count update state.

## 1. Create Supabase project

Create a Supabase project and open **SQL Editor**.

Run the complete file:

`supabase_schema.sql`

The PHP application also runs the same `CREATE TABLE IF NOT EXISTS` schema automatically when it connects, so running the SQL manually is optional but recommended for first setup.

## 2. Get the PostgreSQL connection string

Use the PostgreSQL connection string supplied by Supabase. Put it in Render as:

```text
DATABASE_URL=postgresql://...
```

Do **not** commit the password or connection string to GitHub.

## 3. Render environment variables

Keep your existing variables and add:

```text
DATABASE_URL=YOUR_SUPABASE_POSTGRES_CONNECTION_STRING
```

Remove the old SQLite `BOT_DB_PATH` variable. It is no longer used.

Keep these Telegram variables:

```text
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=
ADMIN_BOT_TOKEN=
ADMIN_WEBHOOK_SECRET=
ADMIN_IDS=
ADMIN_CHAT_ID=
```

Keep your attendance variables:

```text
ATTENDANCE_API_URL=https://attendancechecker-o41l.onrender.com/index.php?api=attendance
ATTENDANCE_API_KEY=
```

## 4. Deploy

The Dockerfile now installs PHP PostgreSQL support (`pdo_pgsql`) instead of depending on SQLite.

After deployment, test:

1. Open the Render service URL.
2. Send `/start` to `@live_attendance_checker_bot`.
3. Send a valid Roll Number.
4. Open `@stu_checked_info_bot` and run `/stats`.
5. Run `/suspend ROLL test` from the admin bot.
6. Try the same Roll Number from the public bot.
7. Confirm it is rejected without contacting the attendance scraper.
8. Run `/unsuspend ROLL` and test again.

## 5. Webhooks

Set the public bot webhook to:

```text
https://api.telegram.org/bot<USER_BOT_TOKEN>/setWebhook?url=https%3A%2F%2FYOUR_RENDER_DOMAIN%2Ftelegram_bot.php&secret_token=<USER_WEBHOOK_SECRET>
```

Set the admin bot webhook to:

```text
https://api.telegram.org/bot<ADMIN_BOT_TOKEN>/setWebhook?url=https%3A%2F%2FYOUR_RENDER_DOMAIN%2Fadmin_bot.php&secret_token=<ADMIN_WEBHOOK_SECRET>
```

## Important security behavior

### Suspended Roll Numbers

The public bot checks Supabase before calling the attendance API.

The attendance API (`index.php`) also checks Supabase before `get_attendance()`.

Therefore a suspended Roll Number is blocked at **both layers**.

If Supabase is unavailable, the API fails closed with HTTP 503 instead of bypassing the suspension system.

### User privacy

Each attendance response is sent only to the requesting Telegram chat. User records and attendance request history are stored server-side and are not displayed to other public-bot users.

### Unique users

A Telegram user is identified by `telegram_id`. Repeated Roll Number checks by the same Telegram user do not create another user record.

### Broadcast

Only users who have previously interacted with the public bot can be messaged by it. Users who block/deactivate the bot are marked inactive when Telegram reports a blocked/deactivated/chat-not-found error.
