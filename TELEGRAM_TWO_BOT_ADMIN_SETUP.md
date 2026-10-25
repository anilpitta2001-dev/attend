# Two Telegram Bot Setup

## Existing bots

1. `@live_attendance_checker_bot` = public User Bot
2. `@stu_checked_info_bot` = private Admin Bot

No third bot is required.

## 1. Render environment variables

Set:

```text
TELEGRAM_BOT_TOKEN=<token for @live_attendance_checker_bot>
TELEGRAM_WEBHOOK_SECRET=<random secret>

ADMIN_BOT_TOKEN=<token for @stu_checked_info_bot>
ADMIN_WEBHOOK_SECRET=<different random secret>

ADMIN_IDS=<your numeric Telegram user ID>
ADMIN_CHAT_ID=<your numeric Telegram user ID>
```

`ADMIN_CHAT_ID` is kept for compatibility with the existing logging setup. `ADMIN_IDS` is the authorization list.

Also keep your existing attendance/API/proxy variables.

## 2. Persistent database

The new bot uses:

```text
`DATABASE_URL`
```

For production, configure the Supabase PostgreSQL connection string in Render as `DATABASE_URL`.

Without persistence, a container replacement can reset:
- user count
- suspended Roll Numbers
- attendance request history
- broadcast history

## 3. Set User Bot webhook

After deployment:

```text
https://api.telegram.org/bot<USER_BOT_TOKEN>/setWebhook?url=https%3A%2F%2Fattend-ctn4.onrender.com%2Ftelegram_bot.php&secret_token=<USER_WEBHOOK_SECRET>
```

## 4. Set Admin Bot webhook

```text
https://api.telegram.org/bot<ADMIN_BOT_TOKEN>/setWebhook?url=https%3A%2F%2Fattend-ctn4.onrender.com%2Fadmin_bot.php&secret_token=<ADMIN_WEBHOOK_SECRET>
```

## 5. Admin test

Open `@stu_checked_info_bot` from the Telegram account whose numeric ID is in `ADMIN_IDS`.

Run:

```text
/start
/stats
```

Then test:

```text
/suspend 24C31AXXXX test
/suspended
/unsuspend 24C31AXXXX
```

## 6. Broadcast test

Use a very small test first:

```text
/broadcast Test announcement from Attendance Checker.
```

The message is sent using the public User Bot to registered users.

## 7. Important

Do not paste real tokens into GitHub.

Do not expose `ADMIN_BOT_TOKEN` to students.

Do not expose the Supabase PostgreSQL database file publicly.
