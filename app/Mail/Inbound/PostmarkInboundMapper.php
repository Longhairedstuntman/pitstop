<?php

namespace App\Mail\Inbound;

/**
 * Maps Postmark's inbound-parse JSON onto the provider-agnostic InboundEmail.
 *
 * Payload reference: https://postmarkapp.com/developer/webhooks/inbound-webhook
 *
 * Everything here is attacker-controlled — anyone can send mail to the help
 * desk address — so every field is treated as optional and coerced to the
 * expected type rather than trusted.
 */
class PostmarkInboundMapper
{
    public function map(array $payload): InboundEmail
    {
        $headers = $this->mapHeaders($payload['Headers'] ?? []);

        $fromFull = is_array($payload['FromFull'] ?? null) ? $payload['FromFull'] : [];

        $fromEmail = $this->string($fromFull['Email'] ?? null)
            ?? $this->extractAddress($this->string($payload['From'] ?? null))
            ?? '';

        return new InboundEmail(
            messageId: $this->string($payload['MessageID'] ?? null)
                ?? $this->string($headers['message-id'] ?? null),
            inReplyTo: $this->string($headers['in-reply-to'] ?? null),
            references: $this->string($headers['references'] ?? null),
            fromEmail: mb_strtolower(trim($fromEmail)),
            fromName: $this->string($fromFull['Name'] ?? null) ?: null,
            toEmail: $this->string($payload['OriginalRecipient'] ?? null)
                ?? $this->extractAddress($this->string($payload['To'] ?? null)),
            subject: $this->string($payload['Subject'] ?? null),
            textBody: $this->string($payload['TextBody'] ?? null),
            htmlBody: $this->string($payload['HtmlBody'] ?? null),
            strippedTextReply: $this->string($payload['StrippedTextReply'] ?? null),
            mailboxHash: $this->resolveMailboxHash($payload),
            attachments: $this->mapAttachments($payload['Attachments'] ?? []),
            headers: $headers,
            rawPayload: $this->redactForStorage($payload),
        );
    }

    /**
     * Postmark reports MailboxHash at the top level and per-recipient. The
     * top-level one is empty when the plus-address was in Cc rather than To,
     * so fall back to scanning the recipient lists.
     */
    protected function resolveMailboxHash(array $payload): ?string
    {
        if ($hash = $this->string($payload['MailboxHash'] ?? null)) {
            return $hash;
        }

        foreach (['ToFull', 'CcFull', 'BccFull'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $recipient) {
                if (!is_array($recipient)) {
                    continue;
                }
                if ($hash = $this->string($recipient['MailboxHash'] ?? null)) {
                    return $hash;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string> lower-cased name => value
     */
    protected function mapHeaders(mixed $headers): array
    {
        $mapped = [];

        foreach ((array) $headers as $header) {
            if (!is_array($header)) {
                continue;
            }

            $name = $this->string($header['Name'] ?? null);
            $value = $this->string($header['Value'] ?? null);

            if ($name === null) {
                continue;
            }

            $mapped[mb_strtolower($name)] = $value ?? '';
        }

        return $mapped;
    }

    /**
     * @return array<int, InboundAttachment>
     */
    protected function mapAttachments(mixed $attachments): array
    {
        $mapped = [];

        foreach ((array) $attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $encoded = $this->string($attachment['Content'] ?? null);
            if ($encoded === null) {
                continue;
            }

            // strict mode: malformed base64 yields false rather than garbage.
            $content = base64_decode($encoded, true);
            if ($content === false) {
                continue;
            }

            $mapped[] = new InboundAttachment(
                name: $this->string($attachment['Name'] ?? null) ?? 'attachment',
                content: $content,
                contentType: $this->string($attachment['ContentType'] ?? null),
                contentId: $this->string($attachment['ContentID'] ?? null) ?: null,
            );
        }

        return $mapped;
    }

    /**
     * Strip the bulky base64 attachment blobs before the payload is persisted
     * to `ticket_email_messages.raw_payload` — the files themselves are
     * already on disk, and keeping a second copy in the database would bloat
     * it enormously.
     */
    protected function redactForStorage(array $payload): array
    {
        if (isset($payload['Attachments']) && is_array($payload['Attachments'])) {
            $payload['Attachments'] = array_map(function ($attachment) {
                if (is_array($attachment)) {
                    unset($attachment['Content']);
                    $attachment['_content_redacted'] = true;
                }

                return $attachment;
            }, $payload['Attachments']);
        }

        return $payload;
    }

    protected function string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Pull bare@address out of `Display Name <bare@address>`.
     */
    protected function extractAddress(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            return trim($matches[1]);
        }

        return trim($value);
    }
}
