# Production Readiness Checklist

## Environment
- Set `APP_ENV=production` and `APP_DEBUG=false`.
- Set `APP_URL` to your HTTPS domain.
- Set `APP_KEY` (run `php artisan key:generate`).
- Set `OPS_NOTIFY_EMAIL` for new ticket notifications.
- Configure mail (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_*`).
- Configure database (`DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
- Configure cache/queue/session if using Redis or another backend.

## Build & Cache
- `composer install --no-dev --optimize-autoloader`
- `npm ci && npm run build`
- `php artisan migrate --force`
- `php artisan storage:link`
- `php artisan config:cache`
- `php artisan route:cache`
- `php artisan view:cache`

## Permissions
- Ensure `storage/` and `bootstrap/cache/` are writable by the web server user.

## Queue & Scheduler
- Run a queue worker (database queue by default):
  - `php artisan queue:work --tries=3`
- **Required for the email help desk.** Inbound mail is processed on the queue.
  With no worker running, email arrives, the webhook returns 200, and the job
  sits unprocessed in the `jobs` table — nothing reaches the ops queue. Run the
  worker under supervisor/systemd so it restarts.
- If you add scheduled tasks later, configure a cron for `php artisan schedule:run`.

## Email help desk
See `HELPDESK_EMAIL.md` for the full setup. Before go-live:
- Set `HELPDESK_INBOUND_ADDRESS` and a long random `HELPDESK_INBOUND_SECRET`.
  The webhook fails closed (503) without the secret.
- Point the inbound provider's webhook at
  `https://<domain>/webhooks/inbound-email/<HELPDESK_INBOUND_SECRET>` over HTTPS only.
- Forward the help desk mailbox to the provider's inbound address.
- Verify SPF, DKIM and Return-Path for the sending domain, or replies land in spam.
- Keep `HELPDESK_ATTACHMENT_DISK` on a **private** disk — attachments come from
  unauthenticated senders and are served through an authorisation check.
- Send a test message end to end and confirm a ticket appears in the ops queue.

## Health Check
- `/up` route is enabled for health monitoring.

## Notes
- Page-specific CSS files are built via Vite; keep them listed in `vite.config.js` inputs.
- Attachments are stored on the `public` disk; keep the `storage` symlink in place.
