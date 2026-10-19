<?php

namespace App\Mail\Inbound;

/**
 * Provider-agnostic representation of one received email.
 *
 * Every inbound transport (Postmark webhook today; Mailgun, Graph or IMAP
 * later) maps its own payload into this shape, and the ingestion pipeline
 * only ever sees this. Adding a provider means adding a mapper, not touching
 * threading, attachment handling or ticket creation.
 */
class InboundEmail
{
    /**
     * @param  array<int, InboundAttachment>  $attachments
     * @param  array<string, string>  $headers  Lower-cased header name => value
     */
    public function __construct(
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly ?string $references,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly ?string $toEmail,
        public readonly ?string $subject,
        public readonly ?string $textBody,
        public readonly ?string $htmlBody,
        public readonly ?string $strippedTextReply,
        public readonly ?string $mailboxHash,
        public readonly array $attachments = [],
        public readonly array $headers = [],
        public readonly array $rawPayload = [],
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[mb_strtolower($name)] ?? null;
    }

    /**
     * The body to store, preferring the provider's quote-stripped version.
     *
     * Without this every reply carries the entire thread beneath it and the
     * ticket becomes unreadable after three exchanges.
     */
    public function bestTextBody(): string
    {
        $candidates = [
            $this->strippedTextReply,
            $this->textBody,
            $this->htmlBody ? strip_tags($this->htmlBody) : null,
        ];

        foreach ($candidates as $candidate) {
            if (filled($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '(no message body)';
    }

    /**
     * Subject with the "Re:"/"Fwd:" prefixes stripped, for use as a ticket title.
     */
    public function cleanSubject(): string
    {
        $subject = trim((string) $this->subject);

        if ($subject === '') {
            return '(no subject)';
        }

        // Strip any run of reply/forward prefixes, including localised ones
        // commonly seen in Outlook (AW:, SV:, VS:, RES:, ENC:).
        $pattern = '/^(?:\s*(?:re|aw|sv|vs|res|enc|fw|fwd|rv)\s*(?:\[\d+\])?\s*:\s*)+/i';
        $subject = preg_replace($pattern, '', $subject) ?? $subject;

        // Remove our own subject tag if the sender kept it.
        $prefix = preg_quote((string) config('helpdesk.subject_prefix', 'PS'), '/');
        $subject = preg_replace('/\s*\[#'.$prefix.'-\d+-[a-z0-9]{6,32}\]\s*/i', ' ', $subject) ?? $subject;

        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? $subject);

        return $subject === '' ? '(no subject)' : mb_substr($subject, 0, 150);
    }

    /**
     * True when this looks like an automated message that must not open or
     * reply to a ticket.
     *
     * Checks the standard RFC 3834 / Microsoft / Postfix signals. Getting this
     * wrong in the permissive direction means an out-of-office bouncing off an
     * auto-acknowledgement in an endless loop.
     */
    public function isAutomated(): bool
    {
        $autoSubmitted = mb_strtolower((string) $this->header('auto-submitted'));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        if (filled($this->header('x-autoreply'))
            || filled($this->header('x-autorespond'))
            || filled($this->header('list-unsubscribe'))) {
            return true;
        }

        $precedence = mb_strtolower((string) $this->header('precedence'));
        if (in_array($precedence, ['bulk', 'auto_reply', 'junk', 'list'], true)) {
            return true;
        }

        // Exchange / Outlook out-of-office.
        $msgClass = mb_strtolower((string) $this->header('x-auto-response-suppress'));
        if ($msgClass !== '') {
            return true;
        }

        if (mb_strtolower((string) $this->header('x-ms-exchange-inbox-rules-loop')) !== '') {
            return true;
        }

        // Null return-path marks a bounce.
        $returnPath = trim((string) $this->header('return-path'), '<> ');
        if ($returnPath === '') {
            $hasReturnPath = array_key_exists('return-path', $this->headers);
            if ($hasReturnPath) {
                return true;
            }
        }

        return false;
    }
}
