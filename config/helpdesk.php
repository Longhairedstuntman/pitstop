<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inbound address
    |--------------------------------------------------------------------------
    |
    | The public help desk address people email, e.g. helpdesk@mwmotor.com.
    | Outbound mail is sent From this address and Reply-To a plus-addressed
    | variant of it so replies thread back to the right ticket.
    |
    */

    'inbound_address' => env('HELPDESK_INBOUND_ADDRESS'),

    'from_name' => env('HELPDESK_FROM_NAME', 'MWM Help Desk'),

    /*
    | Where new-ticket and new-reply notifications go. Falls back to the
    | existing OPS_NOTIFY_EMAIL so nothing breaks for current deployments.
    */

    'ops_notify_email' => env('HELPDESK_OPS_NOTIFY_EMAIL', env('OPS_NOTIFY_EMAIL')),

    /*
    |--------------------------------------------------------------------------
    | Subject tag prefix
    |--------------------------------------------------------------------------
    |
    | Used to build the "[#PS-412-a1b2c3d4e5f6]" tag appended to outbound
    | subjects. Changing this after go-live breaks subject-tag matching on
    | threads already in flight; header matching keeps working regardless.
    |
    */

    'subject_prefix' => env('HELPDESK_SUBJECT_PREFIX', 'PS'),

    /*
    |--------------------------------------------------------------------------
    | Inbound webhook secret
    |--------------------------------------------------------------------------
    |
    | Postmark's inbound webhook carries no signature, so the endpoint is
    | protected by an unguessable token in the URL. Set this to a long random
    | string and give the provider the URL containing it.
    |
    | Optionally also set basic-auth credentials and configure them in the
    | provider's webhook URL (https://user:pass@host/...). When set, both the
    | token and the credentials must match.
    |
    */

    'inbound_secret' => env('HELPDESK_INBOUND_SECRET'),

    'inbound_basic_auth_user' => env('HELPDESK_INBOUND_BASIC_USER'),
    'inbound_basic_auth_password' => env('HELPDESK_INBOUND_BASIC_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Auto-acknowledgement
    |--------------------------------------------------------------------------
    |
    | Send the requester a "we got it, here's your ticket number" email when a
    | ticket is opened by email. Turn this off if the volume of bot and
    | newsletter mail hitting the address makes it noisy.
    |
    */

    'send_auto_acknowledgement' => (bool) env('HELPDESK_AUTO_ACK', true),

    /*
    |--------------------------------------------------------------------------
    | Default category and priority for emailed tickets
    |--------------------------------------------------------------------------
    |
    | Email has no category picker, so tickets arriving this way start here and
    | get triaged in the ops console.
    |
    */

    'email_default_category' => env('HELPDESK_EMAIL_CATEGORY', 'General'),

    /*
    |--------------------------------------------------------------------------
    | Reopen window
    |--------------------------------------------------------------------------
    |
    | A reply to a ticket closed within this many days reopens it. Beyond that,
    | the message starts a fresh ticket instead — otherwise a "thanks!" sent
    | months later resurrects long-dead work.
    |
    | Set to 0 to always reopen, no matter how old.
    |
    */

    'reopen_window_days' => (int) env('HELPDESK_REOPEN_WINDOW_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Attachments are written to a private disk and served through a gated
    | controller — never a public URL. `max_bytes` is enforced per file on
    | inbound email; oversize attachments are dropped and noted on the ticket.
    |
    */

    'attachment_disk' => env('HELPDESK_ATTACHMENT_DISK', 'local'),

    'attachment_max_bytes' => (int) env('HELPDESK_ATTACHMENT_MAX_BYTES', 10 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Loop protection
    |--------------------------------------------------------------------------
    |
    | Addresses and patterns that must never open a ticket. The help desk's own
    | address is always blocked, in addition to anything listed here, because a
    | mail loop between two auto-responders will fill the database overnight.
    |
    */

    'blocked_senders' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('HELPDESK_BLOCKED_SENDERS', '')
    )))),

    /*
     | Local-parts that are almost always machine senders. Matched against the
     | portion before the "@", case-insensitively.
     */
    'blocked_sender_prefixes' => [
        'mailer-daemon',
        'postmaster',
        'no-reply',
        'noreply',
        'donotreply',
        'do-not-reply',
        'bounce',
        'bounces',
    ],

    /*
     | Maximum tickets a single sender may open per hour. Further mail from
     | that address within the window is appended to their most recent ticket
     | instead of opening new ones.
     */
    'rate_limit_per_sender_per_hour' => (int) env('HELPDESK_SENDER_RATE_LIMIT', 20),

];
