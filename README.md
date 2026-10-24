# BITS Attendance Checker + Telegram User/Admin Bots

## Optional Render A/B webhook failover
See [`FAILOVER_SETUP.md`](FAILOVER_SETUP.md) for the Cloudflare webhook controller, automatic failover, manual `/switchserver A|B`, required secrets, and deployment steps. This is separate from the existing Cloudflare proxy used by `index.php` to reach the college portal.


This version keeps the existing working attendance scraper and upgrades the two existing Telegram bots.

## Two-bot architecture

### 1. `@live_attendance_checker_bot` — Public/User Bot
Students use this bot to:
- send a Roll Number
- receive their own attendance result
- refresh attendance
- change Roll Number

It also:
- counts each Telegram account only once as a unique user
- records attendance requests
- checks Roll Number suspension BEFORE calling the attendance API
- updates the bot display name to show the unique-user count where supported
- sends private lookup logs to the Admin Bot

### 2. `@stu_checked_info_bot` — Private Admin Bot
This is the existing second bot, upgraded from logger-only to admin control.

Only `ADMIN_IDS` can use:
- `/stats`
- `/users`
- `/checks`
- `/topchecks` — most checked roll numbers
- `/userchecks ROLL` — Telegram accounts checking a specific roll
- `/suspend ROLL reason`
- `/unsuspend ROLL`
- `/suspended`
- `/broadcast MESSAGE`

The existing automatic attendance lookup notifications continue to arrive in this bot.

## Data/privacy separation

A student's attendance response is sent only to the Telegram chat that requested it.

The database associates:
- Telegram user ID
- Chat ID
- Roll Number request
- request time
- request status/result

One Telegram user checking many Roll Numbers remains one unique user.

## Roll Number suspension

Admin:
`/suspend 24C31AXXXX Invalid request`

The User Bot checks the database before calling `ATTENDANCE_API_URL`.

If suspended:
- no attendance API request is made
- no college portal fetch occurs
- the student receives a suspension message

Restore:
`/unsuspend 24C31AXXXX`

List:
`/suspended`

## Broadcast

Admin:
`/broadcast 📢 Important notice: attendance portal maintenance at 6 PM.`

The Admin Bot sends the message through the public User Bot token to registered user chat IDs.

Failed/blocked users are recorded and blocked/deactivated users can be marked inactive for future broadcasts.

## Database

The bots use persistent Supabase PostgreSQL through `DATABASE_URL`.

Tables:
- `users`
- `attendance_requests`
- `suspended_roll_numbers`
- `broadcasts`
- `bot_settings`

### IMPORTANT Render persistence

Supabase PostgreSQL is the production database. Both Telegram bots and the attendance API suspension guard use the same database.

If you do not have persistent storage, the bot will still run, but the user count, suspension list and history can be lost after the container is recreated.

## Docker

The Dockerfile installs PHP `pdo_pgsql` for Supabase PostgreSQL.

## Environment variables

Set all values in Render. Never commit real tokens/passwords to GitHub.

Required for Telegram features:
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `ADMIN_BOT_TOKEN`
- `ADMIN_WEBHOOK_SECRET`
- `ADMIN_IDS`
- `ATTENDANCE_API_URL`
- `ATTENDANCE_API_KEY` if your API is protected
- `DATABASE_URL`

Existing scraper variables remain required:
- `BITS_PORTAL_USERNAME`
- `BITS_PORTAL_PASSWORD`
- `BITS_PORTAL_URL`
- `USE_PROXY`
- `PROXY_URL`
- `PROXY_KEY`

## Webhooks

### User bot

After deployment:

`https://api.telegram.org/bot<USER_BOT_TOKEN>/setWebhook?url=https%3A%2F%2Fattendancechecker-o41l.onrender.com%2Ftelegram_bot.php&secret_token=<USER_WEBHOOK_SECRET>`

### Admin bot

Set a separate webhook:

`https://api.telegram.org/bot<ADMIN_BOT_TOKEN>/setWebhook?url=https%3A%2F%2Fattendancechecker-o41l.onrender.com%2Fadmin_bot.php&secret_token=<ADMIN_WEBHOOK_SECRET>`

The admin bot therefore becomes interactive, while lookup logs continue to be sent automatically to it.

## Bot display name

When a new unique user is registered, the User Bot attempts to update its display name to:

`Attendance Checker • 👥 58 Users`

Telegram controls the final display/update behavior and may apply rate limits. The system only attempts an update when the stored unique-user count changes.

## Existing files retained

- `index.php` — existing website + attendance scraper + JSON API
- `telegram_bot.php` — upgraded public bot
- `admin_bot.php` — new admin endpoint for the existing admin bot
- `telegram_cron.php` — existing scheduled attendance feature
- `Dockerfile`
- `php.ini`

## Security

- Admin commands require an exact Telegram numeric ID in `ADMIN_IDS`.
- Keep both bot tokens private.
- Keep college portal credentials private.
- Keep `ATTENDANCE_API_KEY` private.
- Keep webhook secrets private.


## Supabase / Render

See `SUPABASE_RENDER_SETUP.md` for the complete database, environment-variable, webhook, and deployment procedure. Run `supabase_schema.sql` in Supabase SQL Editor before production deployment.


## User support commands

- Public users can send `/support` to request help, or `/support Your message` to send a message to the administrator.
- Support notifications arrive in the Admin_Info bot and include the user's numeric Telegram ID.
- Admins can reply with `/supportid TELEGRAM_ID Your reply`. The reply is sent through the public `@live_attendance_checker_bot`.
- Ensure `ADMIN_CHAT_ID` is set to the admin chat ID and both bot tokens are configured.
- Public and admin webhooks deduplicate Telegram `update_id` values in PostgreSQL to prevent retry-triggered duplicate side effects.


## Support contact commands

Public bot (`@live_attendance_checker_bot`):
- `/support` sends a support request notification to the configured admin chat.
- `/support Your message here` sends the user's message with their Telegram ID, name and username (if set).
- The public bot confirms that the request was submitted.

Admin bot (`@stu_checked_info_bot`):
- `/supportid TELEGRAM_ID Your reply here` sends a private reply through the public bot to that Telegram user.
- The recipient must have started the public bot before Telegram will allow the bot to message them.
- `ADMIN_CHAT_ID` is the private chat destination for support notifications; `ADMIN_IDS` controls who may run admin commands.

## Duplicate webhook / broadcast protection

Both webhook handlers persist each Telegram `update_id` in PostgreSQL before processing it. Telegram retries of the same webhook update are acknowledged without running the command again. If the database cannot be reached, webhook processing fails closed instead of risking repeated side effects. This does not prevent an administrator from intentionally sending the same `/broadcast` command twice as two separate Telegram messages.

## Maintenance and unreachable API

- Set `MAINTENANCE_MODE=true` in Render environment variables to return the planned maintenance message for attendance requests.
- With maintenance mode off, network timeouts, HTTP 5xx and an unreachable attendance API also return a service-unavailable/maintenance response.
- The public Telegram webhook must remain online to send that response. If the Render service hosting the webhook itself is fully suspended, it cannot respond until another always-available webhook host receives the update.

## Deployment checklist

1. Keep real bot tokens, webhook secrets, database credentials and proxy keys out of GitHub.
2. Set `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `ADMIN_BOT_TOKEN`, `ADMIN_WEBHOOK_SECRET`, `ADMIN_IDS`, `ADMIN_CHAT_ID`, and `DATABASE_URL` in the host environment.
3. Confirm `ATTENDANCE_API_URL` points to the current attendance API and configure `ATTENDANCE_API_KEY` if the API requires it.
4. Ensure the Telegram webhook URLs are set to the correct deployed endpoints with their configured secret tokens.
5. Deploy, then test `/start`, one roll-number lookup, `/support`, `/supportid TELEGRAM_ID test reply`, `/help`, and one controlled `/broadcast` to a small test audience before using it broadly.
6. Verify the Supabase schema is applied using `supabase_schema.sql`. The webhook handlers also create their own update-deduplication tables on first use.

## Validation

The PHP files in this package were syntax-checked with `php -l`. This package has not been live-tested against your Telegram bots, Render service, college portal, or Supabase account; run the deployment checklist before production use.


## Critical broadcast safety change

`/broadcast MESSAGE` no longer sends messages immediately. It creates a five-minute, one-time confirmation token. Only `/confirmbroadcast TOKEN` from the authorized admin's private chat starts delivery. The token is atomically consumed, so replays of the confirmation cannot launch the same broadcast again. If a stale webhook update contains an old `/broadcast` command, it can at most create a confirmation prompt; it cannot broadcast automatically.


## Reliability and error-message updates (attendance-4)

- The public Telegram bot now distinguishes network/endpoint failures, invalid API configuration, invalid roll numbers, suspended roll numbers, throttling, and upstream server errors instead of labeling every error "developer enabled maintenance mode".
- The attendance scraper treats upstream HTTP error statuses (including 421/502) as failures, records safe diagnostic details in server logs, and no longer disables TLS certificate verification for direct requests.
- Missing portal credentials or an enabled-but-unconfigured proxy are detected before login attempts.
- `/broadcast MESSAGE` now creates a pending draft and does not send messages. Review the preview and active recipient count, then send `/confirmbroadcast TOKEN` within five minutes. `/cancelbroadcast` discards the pending draft. Tokens are hashed in PostgreSQL, bound to the admin chat, expire, and are consumed atomically once.
- No application can guarantee zero defects without a live test against the actual Render environment, proxy, college portal, Telegram and Supabase. Review Render logs after deploying.
