<?php

namespace App\Mail\Inbound;

/**
 * One attachment from an inbound email, with its content already decoded.
 */
class InboundAttachment
{
    public function __construct(
        public readonly string $name,
        public readonly string $content,
        public readonly ?string $contentType = null,
        public readonly ?string $contentId = null,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }

    /**
     * True for an image embedded in the HTML body rather than a real
     * attachment — these are signature logos nine times out of ten.
     */
    public function isInline(): bool
    {
        return filled($this->contentId);
    }

    /**
     * A filesystem-safe name, stripped of any path components.
     *
     * Attachment names come from the sender and are fully attacker-controlled,
     * so "../../.env" must never survive this.
     */
    public function safeName(): string
    {
        $name = basename(str_replace('\\', '/', $this->name));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? 'attachment';
        $name = ltrim($name, '.');
        $name = trim($name);

        if ($name === '') {
            $name = 'attachment';
        }

        return mb_substr($name, 0, 180);
    }
}
