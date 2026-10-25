# Webhook failover setup (Render A/B + Cloudflare Worker)

This is an addition to the existing Attendance Checker repository. It does **not** replace `index.php`, `telegram_bot.php`, `admin_bot.php`, `db.php`, or the existing outbound Cloudflare college-portal proxy settings (`USE_PROXY`, `PROXY_URL`, `PROXY_KEY`).

## What Cloudflare does here

There are two different jobs:

1. The existing `index.php` proxy (`PROXY_URL`/`PROXY_KEY`) sends the scraper's outgoing requests to the college portal through a Cloudflare Worker.
2. The new `failover-worker.js` receives Telegram webhooks at one stable URL and forwards each update to Render A or Render B. If the selected Render service is unreachable, returns a 5xx error, or serves a recognized Render suspension page, it tries the other service. It stores the selected server in Cloudflare KV.

Cloudflare is the small always-available traffic controller; it is not the attendance scraper or database.

## Important limits

- This is best-effort failover, not a 100% uptime guarantee. Free services can sleep, hit limits, or be unavailable together.
- Both Render services run the same PHP repository and use the same Supabase `DATABASE_URL`.
- Keep the existing bot tokens and college portal credentials out of Git.
- Both PHP webhook endpoints now require `BACKEND_PROXY_SECRET` as well as the Telegram webhook secret. Configure these before switching Telegram's webhooks, or requests will be rejected.
- The Worker auto-switches when a webhook request encounters a transport failure, HTTP 5xx, or a recognized Render suspension page. Its health probe also rejects generic HTML responses. `/serverstatus` probes both deployments. `/switchserver A` and `/switchserver B` manually select a healthy deployment.
- Manual switch commands must be sent to the existing Admin Bot in a private chat by the configured `ADMIN_TELEGRAM_ID`. Other admin bot commands continue to run in `admin_bot.php`.

## 1. Deploy the same repository twice on Render

Create two Web Services from the same GitHub repository and branch:
- Service A: your existing deployment.
- Service B: backup deployment.

Use the existing Dockerfile / deployment method. Configure the same application environment variables on both services, including:
- `DATABASE_URL` (same Supabase database)
- `BACKEND_PROXY_SECRET` (a new long random secret)
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `ADMIN_BOT_TOKEN`
- `ADMIN_WEBHOOK_SECRET`
- `ADMIN_IDS` and `ADMIN_CHAT_ID`
- all existing college portal and attendance API variables required by your app.

Do not set the Telegram webhook directly to either Render URL after migration. The Worker becomes the webhook entry point. The Render service URLs are still used internally by the Worker.

## 2. Create the Cloudflare Worker

In Cloudflare Dashboard:
1. Create a Worker named `attendance-webhook-controller`.
2. Copy the contents of `failover-worker.js` into the Worker editor and deploy.
3. Create a KV namespace (Workers & Pages → KV), then add a binding named exactly `BOT_CONFIG` to the Worker.
4. Add these Worker environment variables/secrets:
   - `BACKEND_A_URL` = complete base URL of Render A, e.g. `https://your-primary.onrender.com`
   - `BACKEND_B_URL` = complete base URL of Render B, e.g. `https://your-backup.onrender.com`
   - `BACKEND_PROXY_SECRET` = same value configured on both Render services
   - `TELEGRAM_BOT_TOKEN` = existing public bot token (not used for user routing but retained for configuration consistency)
   - `TELEGRAM_WEBHOOK_SECRET` = same value as Render's `TELEGRAM_WEBHOOK_SECRET`
   - `ADMIN_BOT_TOKEN` = existing admin bot token
   - `ADMIN_WEBHOOK_SECRET` = same value as Render's `ADMIN_WEBHOOK_SECRET`
   - `ADMIN_TELEGRAM_ID` = your numeric personal Telegram user ID
5. Visit `https://YOUR-WORKER.workers.dev/health`. It should return JSON with `"ok":true`.

Use long random strings for all secrets. Do not put real values in `wrangler.toml`, `.env.example`, or GitHub.

## 3. Set both Telegram webhooks

Run these requests privately from a secure terminal, replacing placeholders. Do not share the URLs containing bot tokens.

Public bot:
```bash
curl -X POST "https://api.telegram.org/bot<PUBLIC_BOT_TOKEN>/setWebhook" \
  -d "url=https://YOUR-WORKER.workers.dev/user" \
  -d "secret_token=<TELEGRAM_WEBHOOK_SECRET>"
```

Admin bot:
```bash
curl -X POST "https://api.telegram.org/bot<ADMIN_BOT_TOKEN>/setWebhook" \
  -d "url=https://YOUR-WORKER.workers.dev/admin" \
  -d "secret_token=<ADMIN_WEBHOOK_SECRET>"
```

Telegram webhook secret tokens must use the allowed characters and length documented by Telegram. Check each `setWebhook` response for `"ok": true`.

## 4. Commands

Send these to the private Admin Bot from the configured admin account:
- `/serverstatus` — show configured active server and health of A/B.
- `/switchserver A` — manually select healthy A.
- `/switchserver B` — manually select healthy B.

The Worker refuses a manual switch to a server that fails its health probe. If the active server fails while processing a webhook, it tries the other server and updates the active selection after a successful response.

## 5. Test before relying on it

1. Confirm both Render URLs respond to `GET /telegram_bot.php`.
2. Confirm `/health` on the Worker.
3. Set both webhooks and send `/serverstatus`.
4. Send a normal attendance request and verify the existing bot response.
5. Switch A → B, then B → A.
6. Temporarily stop or make A unavailable and test a new update. Restore A after testing.
7. Check Render logs and Supabase records to confirm no duplicate side effects.

The existing PHP files use shared PostgreSQL tables to deduplicate Telegram `update_id` values. Keep both services pointed at the same database. If a request is accepted by a PHP handler but its response is lost, the backup can receive the same update; the shared deduplication tables prevent the update from being processed a second time.

## Rollback

To roll back, set each bot's webhook directly to its original Render URL and retain its `secret_token`. The new PHP backend proxy-secret check means the Render service will still require the `X-Attendance-Proxy-Secret` header, so rollback requires temporarily disabling that check in both PHP files or continuing to use the Worker. Prefer continuing to use the Worker unless you intentionally revert the code changes.
