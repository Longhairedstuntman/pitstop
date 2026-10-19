<?php

namespace App\Mail\Concerns;

use App\Models\Ticket;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Headers;

/**
 * Shared threading behaviour for requester-facing ticket mail.
 *
 * Three things have to line up for a reply to come back to the right ticket:
 *
 *   From      the help desk address, so replies go somewhere we read
 *   Reply-To  a plus-addressed variant carrying the ticket's hash
 *   Headers   Message-ID / In-Reply-To / References, so clients thread it
 *
 * Subject tagging is the belt-and-braces fourth signal.
 */
trait ThreadsTicketEmail
{
    public function headers(): Headers
    {
        $text = [];

        if (filled($this->inReplyTo)) {
            // addTextHeader is raw, so the angle brackets are ours to add.
            // Laravel's messageId/references helpers add their own.
            $text['In-Reply-To'] = '<'.trim($this->inReplyTo, '<> ').'>';
        }

        return new Headers(
            messageId: filled($this->outboundMessageId)
                ? trim($this->outboundMessageId, '<> ')
                : null,
            references: $this->references,
            text: $text,
        );
    }

    /**
     * The From address, falling back to the app's configured sender when no
     * help desk address is set (local development, mostly).
     */
    protected function helpdeskFrom(): ?Address
    {
        $address = config('helpdesk.inbound_address');

        if (blank($address)) {
            return null;
        }

        return new Address($address, (string) config('helpdesk.from_name', 'Help Desk'));
    }

    /**
     * @return array<int, Address>
     */
    protected function helpdeskReplyTo(Ticket $ticket): array
    {
        $replyTo = $ticket->replyToAddress();

        if (blank($replyTo)) {
            return [];
        }

        return [new Address($replyTo, (string) config('helpdesk.from_name', 'Help Desk'))];
    }
}
