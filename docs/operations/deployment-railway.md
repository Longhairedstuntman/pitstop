# Go-live runbook — Railway + Postmark

Takes the help desk from "runs on my laptop" to "someone emails
`helpdesk@mwmotor.com` and a ticket appears."

Everything in here needs an account you own, so these are the steps **you**
run. The application side is already done and committed.

---

## Why Railway and not Vercel

Vercel is serverless: functions start on a request and stop when it ends.
This app depends on a **long-running queue worker** — the webhook accepts mail
in milliseconds and hands processing to a background job. On Vercel that job
would never run and mail would silently vanish.

Railway runs a persistent container, which is what this needs.

---

## Architecture being deployed

```
                  Railway service (one container)
                  ┌──────────────────────────────────┐
helpdesk@         │  nginx  ──►  php-fpm             │
mwmotor.com       │                                  │
    │             │  supervisord also runs:          │
    ▼             │     php artisan queue:work  ◄────┼── processes inbound mail
Google/M365       │                                  │
 forwards         │  volume → /var/www/html/storage  │
    │             └──────────────────────────────────┘
    ▼                          │
Postmark inbound ──── POST ────┘         Railway Postgres
                                          (tickets, jobs, sessions)
```

**Web and worker share one container on purpose.** A Railway volume attaches
to a single service, and the worker *writes* inbound attachments while the web
process *reads* them for download. Split them and attachment downloads 404.

---

## Step 1 — Push the branch

```bash
cd ~/pitstop
git push -u origin feature/email-helpdesk
```

Merge to `main` when you're ready, or deploy the branch directly.

---

## Step 2 — Railway project

1. <https://railway.app> → **New Project** → **Deploy from GitHub repo**
2. Pick `MWAG-Dev/pitstop`, branch `feature/email-helpdesk` (or `main`).
3. Railway detects the `Dockerfile` and builds. **The first build will fail** —
   there's no database or `APP_KEY` yet. Expected; keep going.

### Add Postgres

**+ New** → **Database** → **Add PostgreSQL**. Nothing to configure.

### Add the storage volume

Service → **Settings** → **Volumes** → **New Volume**

- Mount path: `/var/www/html/storage`
- Size: 1 GB is plenty to start

Without this, **every attachment is deleted on each redeploy** — Railway
containers have an ephemeral filesystem.

---

## Step 3 — Environment variables

Generate the two secrets locally first:

```bash
php artisan key:generate --show                      # APP_KEY
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"    # HELPDESK_INBOUND_SECRET
```

Service → **Variables** → **Raw Editor**, and paste the contents of
`.env.production.example`, filling in the blanks. The `${{Postgres.*}}`
references wire themselves up — leave them exactly as written.

The ones that will bite you if wrong:

| Variable | Why it matters |
|---|---|
| `APP_KEY` | Container refuses to start without it |
| `HELPDESK_INBOUND_SECRET` | Webhook returns 503 until set |
| `QUEUE_CONNECTION=database` | `sync` means inbound mail is never processed |
| `HELPDESK_ATTACHMENT_DISK=local` | Must stay a **private** disk |
| `APP_DEBUG=false` | `true` leaks env vars and stack traces publicly |

### Domain

Settings → **Networking** → **Generate Domain** for a `*.up.railway.app` URL,
or add `helpdesk.mwmotor.com` as a custom domain and set the CNAME Railway
shows. Set `APP_URL` to whichever you use, including `https://`.

Redeploy. The build should go green and `/up` should return 200.

---

## Step 4 — Postmark

1. Sign up at <https://postmarkapp.com> and create a server.
2. **Sending** — copy the Server API Token into both `MAIL_USERNAME` and
   `MAIL_PASSWORD`.
3. **Inbound stream** — copy the inbound address
   (`something@inbound.postmarkapp.com`).
4. Set the **inbound webhook URL** to:

   ```
   https://<your-domain>/webhooks/inbound-email/<HELPDESK_INBOUND_SECRET>
   ```

   Use the real secret — that URL segment *is* the authentication. Postmark's
   inbound webhook carries **no signature**, so there is nothing else to verify
   against. Treat the URL as a credential.

---

## Step 5 — DNS for `mwmotor.com`

Outbound mail claims to be from your domain, so without these, replies land in
spam. Postmark's domain settings page shows the exact records.

| Record | Purpose |
|---|---|
| SPF | Authorises Postmark to send as `mwmotor.com` |
| DKIM | Signs outbound mail |
| Return-Path (CNAME) | Aligns the bounce domain |

Wait for all three to show verified in Postmark before going further.

---

## Step 6 — Point the mailbox at Postmark

**Google Workspace**
Admin → Apps → Google Workspace → Gmail → **Routing**. Add a rule for
`helpdesk@mwmotor.com` forwarding to the Postmark inbound address. Or make the
address a **Group** and add the Postmark address as a member.

**Microsoft 365**
Exchange admin center → Recipients → **Contacts** → add a mail contact for the
Postmark inbound address, then forward the help desk mailbox to it (or use a
mail flow rule to redirect).

Use "forward **and keep a copy**" if you want a human-readable archive in the
mailbox as well.

> If `helpdesk@mwmotor.com` doesn't exist yet, create it first — as a shared
> mailbox or a group, not a licensed user.

---

## Step 7 — Verify end to end

1. **Health:** `curl https://<domain>/up` → 200
2. **Webhook rejects a bad secret:**

   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" -X POST \
     https://<domain>/webhooks/inbound-email/wrong -d '{}' \
     -H "Content-Type: application/json"
   ```

   Expect **403**. If you get 200, the secret isn't set — stop and fix it.

3. **Send a real email** from your phone to `helpdesk@mwmotor.com`.
4. Within a few seconds: a ticket in `/ops/tickets`, and an acknowledgement
   back in your inbox.
5. **Reply to that acknowledgement.** It must land on the *same* ticket. This
   is the single most important check — if it opens a second ticket, threading
   is broken.
6. Reply from the ops console and confirm it reaches your inbox from
   `helpdesk@mwmotor.com`.

---

## Create your first admin

Railway service → **⋯** → **Shell**:

```bash
php artisan tinker
```

```php
\App\Models\User::forceCreate([
    'name' => 'Chase',
    'email' => 'chase@mwmotor.com',
    'password' => bcrypt('CHANGE-THIS'),
    'role' => 'admin',
    'email_verified_at' => now(),
]);
```

Change the password immediately after logging in. Everyone else can self-register
— signup is restricted to `ALLOWED_SIGNUP_DOMAINS`.

---

## When something is wrong

**Mail accepted but no ticket appears.** The worker isn't running. Railway logs
should show `queue-worker` lines from supervisord. Check `QUEUE_CONNECTION` is
`database`, not `sync`. Pending rows in the `jobs` table with no worker is the
signature.

**Every reply opens a new ticket.** Outbound Message-IDs aren't being recorded.
Check `ticket_email_messages` has rows with `direction = 'outbound'`.

**Attachments 404 after a redeploy.** The volume isn't mounted at
`/var/www/html/storage`.

**Replies land in spam.** SPF/DKIM/Return-Path aren't verified.

**Webhook returns 503.** `HELPDESK_INBOUND_SECRET` is unset. This is deliberate —
the endpoint fails closed rather than accepting unauthenticated mail.

**502 right after deploy.** Migrations failed in the entrypoint. Read the deploy
logs; it's usually a database variable that didn't resolve.

Everything the pipeline does is logged under the `Helpdesk:` prefix, including
why a message was dropped.

---

## Costs

Railway bills by usage — a container this size plus Postgres is roughly
$10–20/month. Postmark's free tier covers 100 emails/month; the first paid tier
is $15/month for 10,000. For a 12-location dealer help desk, the free tier will
not be enough once this is in real use.

---

## Before it carries real traffic

1. **No per-agent assignment.** Tickets have a status but no owner, so two
   people can work the same one. This is the next thing that will hurt.
2. **No SLA timers or business hours.**
3. **Attachments are capped at 10 MB** and larger ones are dropped with a log
   line — the sender is not told.
4. **No rate limit on the webhook itself.** The per-sender ticket cap limits
   damage, but a flood still costs queue time.
5. **The volume is a single point of failure.** Railway volumes aren't backed
   up by default; attachments live only there. Postgres is backed up separately.
