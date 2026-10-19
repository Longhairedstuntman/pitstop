# Email help desk — setup guide

How `helpdesk@mwmotor.com` becomes a ticket, and what you have to configure to
make it happen.

---

## How it works

```
Someone emails helpdesk@mwmotor.com
        │
        ▼
Google/Microsoft forwards it to Postmark's inbound address
        │
        ▼
Postmark parses the MIME and POSTs JSON to
  https://<your-domain>/webhooks/inbound-email/<HELPDESK_INBOUND_SECRET>
        │
        ▼
InboundEmailController queues ProcessInboundEmail (responds 200 immediately)
        │
        ▼
Queue worker → InboundEmailProcessor
        │
        ├─ already seen this Message-ID?      → drop (idempotent)
        ├─ blocked / machine sender?          → drop
        ├─ auto-reply, bounce, list mail?     → drop
        │
        ├─ threads onto an existing ticket?   → add a reply, reopen if needed
        └─ otherwise                          → open a new ticket + send auto-ack
```

Ops replies from the ticket page go back out **from** the help desk address
with threading headers, so the requester just hits Reply and it lands on the
same ticket.

---

## 1. Postmark setup

1. Create a Postmark server, open the **Inbound** stream.
2. Copy the inbound address it gives you — something like
   `a1b2c3d4e5@inbound.postmarkapp.com`.
3. Set the **Inbound webhook URL** to:

   ```
   https://<your-domain>/webhooks/inbound-email/<HELPDESK_INBOUND_SECRET>
   ```

   Optionally use `https://user:pass@<your-domain>/webhooks/...` and set the
   matching basic-auth env vars for a second layer.

4. Under **Inbound settings**, enable **"Include raw email content"** only if
   you want it; this app does not need it.

### Why a secret in the URL?

Postmark's inbound webhook sends **no signature** — there is nothing to verify
cryptographically. The secret path segment is what stops anyone who discovers
the endpoint from forging tickets from arbitrary addresses. Treat it like a
password: long, random, and rotated if leaked.

`VerifyInboundWebhook` **fails closed** — if `HELPDESK_INBOUND_SECRET` is
unset the endpoint returns 503 rather than accepting anything.

---

## 2. Route the mailbox to Postmark

Pick whichever matches where `mwmotor.com` mail lives.

**Google Workspace**
Admin console → Apps → Google Workspace → Gmail → Routing. Add a rule for the
`helpdesk` address that forwards to the Postmark inbound address.
Simpler alternative: make `helpdesk@mwmotor.com` a Group and add the Postmark
address as a member.

**Microsoft 365**
Exchange admin center → Recipients → Contacts → add a mail contact for the
Postmark inbound address. Then either set the help desk mailbox to forward to
it, or create a mail flow rule that redirects.

**Keep a copy?** Forwarding sends mail onward without retaining it. If you want
a human-readable archive in the mailbox too, use "forward and keep a copy."

> **MX option:** Postmark can take MX for a dedicated subdomain
> (`helpdesk.mwmotor.com`). Cleaner, but it means the address changes. Start
> with forwarding.

---

## 3. Environment variables

Add to `.env`:

```dotenv
# The public help desk address
HELPDESK_INBOUND_ADDRESS=helpdesk@mwmotor.com
HELPDESK_FROM_NAME="MWM Help Desk"

# Long random string; must match the one in the Postmark webhook URL
HELPDESK_INBOUND_SECRET=

# Optional second factor — also put these in the webhook URL
HELPDESK_INBOUND_BASIC_USER=
HELPDESK_INBOUND_BASIC_PASSWORD=

# Where ops notifications go
HELPDESK_OPS_NOTIFY_EMAIL=ops@mwmotor.com

# Behaviour
HELPDESK_AUTO_ACK=true
HELPDESK_EMAIL_CATEGORY=General
HELPDESK_REOPEN_WINDOW_DAYS=30
HELPDESK_SENDER_RATE_LIMIT=20
HELPDESK_SUBJECT_PREFIX=PS

# Attachments — must be a PRIVATE disk
HELPDESK_ATTACHMENT_DISK=local
HELPDESK_ATTACHMENT_MAX_BYTES=10485760

# Never open a ticket from these (comma-separated)
HELPDESK_BLOCKED_SENDERS=

# Outbound SMTP — Postmark's sending stream
MAIL_MAILER=smtp
MAIL_HOST=smtp.postmarkapp.com
MAIL_PORT=587
MAIL_USERNAME=<postmark-server-token>
MAIL_PASSWORD=<postmark-server-token>
MAIL_FROM_ADDRESS=helpdesk@mwmotor.com
MAIL_FROM_NAME="MWM Help Desk"
```

Generate a secret with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Then:

```bash
php artisan migrate --force
php artisan config:cache
```

---

## 4. The queue worker is mandatory

Inbound mail is processed on the queue. **Without a running worker, email
arrives and nothing happens** — the webhook returns 200 and the job sits in the
`jobs` table.

```bash
php artisan queue:work --tries=3
```

Run it under supervisor/systemd so it restarts. To check for a backlog:

```bash
php artisan queue:monitor database:default --max=50
```

---

## 5. SPF / DKIM

Outbound mail claims to be from `mwmotor.com`, so Postmark must be authorised
or replies land in spam:

- **SPF** — include Postmark in the `mwmotor.com` SPF record.
- **DKIM** — add the DKIM record Postmark generates.
- **Return-Path** — add the CNAME Postmark provides for alignment.

Postmark's domain settings page lists the exact records. Verify all three show
green before go-live.

---

## 6. Testing it

**Without sending real mail** — replay a payload against a local server:

```bash
curl -X POST \
  "http://localhost:8000/webhooks/inbound-email/$HELPDESK_INBOUND_SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "From": "you@mwmotor.com",
    "FromFull": {"Email": "you@mwmotor.com", "Name": "You"},
    "To": "helpdesk@mwmotor.com",
    "Subject": "Test request",
    "MessageID": "test-0001@example.com",
    "TextBody": "This is a test.",
    "Headers": [{"Name": "Message-ID", "Value": "<test-0001@example.com>"}],
    "Attachments": []
  }'
```

Then `php artisan queue:work --once` and check the ops queue.

**The automated suite:**

```bash
php artisan test tests/Feature/Helpdesk/
```

---

## How threading works

Four independent strategies, strongest first. Any one is enough — which
matters, because mail clients mangle headers constantly.

| # | Strategy | Beaten by |
|---|----------|-----------|
| 1 | `In-Reply-To` / `References` vs. recorded Message-IDs | Clients that strip headers on forward |
| 2 | Plus-addressed `MailboxHash` (`helpdesk+412abc@`) | Requester emailing the bare address |
| 3 | Subject tag `[#PS-412-abc123]` | Requester editing the subject |
| 4 | Same sender + identical subject + open + <7 days | Deliberately narrow |

**Outbound Message-IDs are recorded too**, and this is load-bearing: when a
requester replies to one of our emails, their `In-Reply-To` is *our* ID. If it
weren't stored, every reply would open a duplicate ticket.

The token in the subject tag is a security control, not decoration. Without it,
anyone could write `[#PS-412]` into a subject and append to ticket 412.

---

## Loop protection

Mail loops are the classic way a homegrown help desk takes itself down
overnight. Dropped automatically:

- Mail from the help desk address itself, including plus-addressed variants
- `Auto-Submitted` other than `no` (RFC 3834 — out-of-office)
- `Precedence: bulk` / `list` / `junk`, and anything with `List-Unsubscribe`
- Exchange's `X-Auto-Response-Suppress` and inbox-rule-loop headers
- Bounces (`MAILER-DAEMON`, `postmaster`, null Return-Path)
- `no-reply@` / `noreply@` / `donotreply@` senders
- Duplicate Message-IDs

A sender exceeding `HELPDESK_SENDER_RATE_LIMIT` tickets/hour has further mail
appended to their most recent ticket instead of opening new ones.

Adjust the lists in `config/helpdesk.php`.

---

## Attachments

Inbound attachments are written to a **private** disk and served through
`TicketAttachmentController`, which checks that the viewer is ops or the
ticket's requester.

This changed existing behaviour: web uploads previously went to the `public`
disk with no database record, meaning they were unreachable from the UI *and*
readable by anyone who guessed the path. An inbound help desk receives
invoices, payroll questions and photos of documents — those must not sit on a
public URL.

Downloads are forced (`Content-Disposition: attachment`, `nosniff`) because
sender-supplied HTML or SVG rendered inline on your own origin is stored XSS.

**Note:** files written under the old scheme to `storage/app/public/tickets/`
have no database rows and won't appear in the UI. There are few of them and
they predate this feature; migrate them manually if any matter.

---

## Operational notes

- `ticket_email_messages.raw_payload` keeps the provider payload (minus
  attachment bytes) for diagnosing a misrouted thread. Prune it periodically.
- Inbound processing failures land in `failed_jobs` with the payload intact, so
  `php artisan queue:retry` replays them.
- Everything logs under the `Helpdesk:` prefix.
- Changing `HELPDESK_SUBJECT_PREFIX` after go-live breaks subject-tag matching
  on in-flight threads; header matching keeps working.

---

## Known gaps

Worth knowing before this carries real volume:

1. **No per-agent assignment.** Tickets have a status but no owner, so two
   people can work the same one.
2. **No SLA timers or business hours.**
3. **Quote stripping leans on Postmark's `StrippedTextReply`**, which is good
   but not perfect; unusual clients may still produce a quoted tail.
4. **No inline-image rendering.** Embedded images are stored with their
   Content-ID but shown as attachments, not rendered in the body.
5. **No knowledge base or canned responses.**
6. **Requester portal still requires an account**, and signup is restricted to
   `mwmotor.com`. External senders interact purely by email — which works, but
   they can't browse their ticket history.
