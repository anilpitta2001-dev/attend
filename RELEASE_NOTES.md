# Consolidated attendance bot release

This package is based on the latest `attendance-3` project uploaded in this conversation and consolidates the requested updates:
- Public `/support` and `/support Your message` flow.
- Admin `/supportid TELEGRAM_ID MESSAGE` reply command.
- PostgreSQL webhook update-id deduplication for both public and admin bot webhooks, preventing Telegram retries from re-running a broadcast or support handler.
- Clear maintenance response for planned maintenance and unreachable/5xx attendance API failures.
- Existing attendance scraper, Supabase integration, suspension checks, lookup logs, and admin commands retained.

## Important
- Do not upload `.env` secrets to GitHub. Set secrets in Render environment variables.
- This is not a guarantee of zero bugs: it has passed PHP syntax validation, but must be tested against your live Telegram, Render, and Supabase setup.
- A Render service that is fully suspended cannot reply to Telegram webhooks. A separately hosted always-on webhook is required if the bot must show a message while Render is suspended.


## Additional broadcast safety
- Broadcast command now requires explicit two-step confirmation using a one-time token.
- Token expires after five minutes and is atomically consumed once.
- Admin commands are restricted to the authorized sender ID in a private chat.
