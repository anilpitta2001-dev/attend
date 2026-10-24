# BITS Attendance Bot — Multi-User + Private Admin Logging

## Architecture

There are two Telegram bots:

1. **Attendance Bot**
   - Public-facing.
   - Users send their roll number.
   - Returns attendance.
   - Does not expose logs or admin commands.

2. **Admin Logger Bot**
   - Separate bot token.
   - Used only by the server to send private activity logs to `ADMIN_CHAT_ID`.
   - No webhook is required for this logger bot.
   - Normal users cannot obtain logs through the attendance bot.

## What is logged

For each attendance lookup:

- Telegram numeric user ID
- Telegram username, if available
- Telegram display name
- Roll number submitted
- Lookup time
- Attendance result
- Branch/name returned by the attendance API
- Lookup source (direct roll, command, refresh button)

Failed lookup attempts are also logged.

## Privacy

The public bot displays a short privacy notice explaining that these fields are used for private usage/security logging.

Do not claim that no data is collected when logging is enabled.

## Render environment variables

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=

ATTENDANCE_API_URL=https://attendancechecker-o41l.onrender.com/index.php?api=attendance
ATTENDANCE_API_KEY=

ADMIN_BOT_TOKEN=
ADMIN_CHAT_ID=
```

## Important setup

1. Create a second bot with BotFather.
2. Start the second bot from your administrator Telegram account.
3. Set its token as `ADMIN_BOT_TOKEN`.
4. Set your Telegram numeric ID as `ADMIN_CHAT_ID`.
5. Do NOT give the admin logger bot token to users.
6. Keep both tokens in Render Environment Variables, never in GitHub source code.
7. The existing attendance webhook URL can remain:
   `https://attendancechecker-o41l.onrender.com/telegram_bot.php`

The logger bot does not need a webhook because the server only sends messages through the Telegram Bot API.

## User commands

- `/start`
- `/help`
- `/privacy`
- `/attendance ROLL`
- Direct roll number

There is intentionally no public `/logs`, `/users`, `/data`, or admin command.
