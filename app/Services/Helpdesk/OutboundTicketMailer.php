<?php

namespace App\Services\Helpdesk;

use App\Mail\TicketAcknowledged;
use App\Mail\TicketReplied;
use App\Models\Ticket;
use App\Models\TicketEmailMessage;
use App\Models\TicketReply;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends requester-facing ticket mail and records what was sent.
 *
 * Recording outbound Message-IDs is not bookkeeping — it is load-bearing.
 * When a requester hits Reply, their client sets In-Reply-To to OUR
 * Message-ID. If that id isn't in `ticket_email_messages`, the threader has
 * nothing to match on and their reply opens a duplicate ticket.
 */
class OutboundTicketMailer
{
    /**
     * Email an ops reply to the requester, threaded onto the conversation.
     */
    public function sendReplyToRequester(Ticket $ticket, TicketReply $reply): void
    {
        $messageId = $this->generateMessageId($ticket);
        $threading = $this->threadingFor($ticket);

        $mailable = new TicketReplied(
            ticket: $ticket,
            reply: $reply,
            recipientType: 'requester',
            outboundMessageId: $messageId,
            inReplyTo: $threading['in_reply_to'],
            references: $threading['references'],
        );

        try {
            Mail::to($ticket->requester_email)->send($mailable);
        } catch (\Throwable $e) {
            Log::error('Helpdesk: failed sending reply to requester', [
                'ticket_id' => $ticket->id,
                'reply_id' => $reply->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->recordOutbound($ticket, $reply, $messageId, $threading);
    }

    /**
     * Email the "we've got it" acknowledgement for a newly opened ticket.
     */
    public function sendAcknowledgement(Ticket $ticket): void
    {
        $messageId = $this->generateMessageId($ticket);
        $threading = $this->threadingFor($ticket);

        $mailable = new TicketAcknowledged(
            ticket: $ticket,
            outboundMessageId: $messageId,
            inReplyTo: $threading['in_reply_to'],
            references: $threading['references'],
        );

        try {
            Mail::to($ticket->requester_email)->send($mailable);
        } catch (\Throwable $e) {
            Log::error('Helpdesk: failed sending acknowledgement', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->recordOutbound($ticket, null, $messageId, $threading);
    }

    /**
     * Build the In-Reply-To and References values for the next message we send
     * on this ticket.
     *
     * In-Reply-To is the most recent message on the thread; References is the
     * full chain, oldest first, which is what well-behaved clients use to
     * group a conversation.
     */
    protected function threadingFor(Ticket $ticket): array
    {
        $messageIds = TicketEmailMessage::where('ticket_id', $ticket->id)
            ->whereNotNull('message_id')
            ->orderBy('id')
            ->pluck('message_id')
            ->all();

        if ($messageIds === []) {
            return ['in_reply_to' => null, 'references' => []];
        }

        return [
            'in_reply_to' => end($messageIds),
            // Cap the chain: some clients choke on enormous References headers,
            // and the first few plus the most recent are what matter.
            'references' => $this->trimReferences($messageIds),
        ];
    }

    /**
     * Keep the References header to a sane length — first 3, last 7 — as
     * recommended for long-running threads.
     */
    protected function trimReferences(array $messageIds, int $max = 10): array
    {
        if (count($messageIds) <= $max) {
            return $messageIds;
        }

        return array_merge(
            array_slice($messageIds, 0, 3),
            array_slice($messageIds, -($max - 3))
        );
    }

    protected function recordOutbound(
        Ticket $ticket,
        ?TicketReply $reply,
        string $messageId,
        array $threading,
    ): void {
        TicketEmailMessage::create([
            'ticket_id' => $ticket->id,
            'ticket_reply_id' => $reply?->id,
            'direction' => TicketEmailMessage::DIRECTION_OUTBOUND,
            'message_id' => TicketEmailMessage::normaliseId($messageId),
            'in_reply_to' => TicketEmailMessage::normaliseId($threading['in_reply_to']),
            'references' => $threading['references'] === []
                ? null
                : implode(' ', array_map(fn ($id) => "<{$id}>", $threading['references'])),
            'from_email' => config('helpdesk.inbound_address'),
            'subject' => mb_substr($ticket->taggedSubject(), 0, 1000),
        ]);
    }

    /**
     * Generate a Message-ID for an outbound message.
     *
     * Shape: ticket-412-9f3a1c2b4d5e@mwmotor.com — the ticket id is embedded
     * so a malformed thread is diagnosable by eye from a mail header.
     */
    protected function generateMessageId(Ticket $ticket): string
    {
        return sprintf(
            'ticket-%d-%s@%s',
            $ticket->id,
            Str::lower(Str::random(16)),
            $this->messageIdDomain()
        );
    }

    protected function messageIdDomain(): string
    {
        $address = (string) config('helpdesk.inbound_address');

        if (str_contains($address, '@')) {
            return explode('@', $address, 2)[1];
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host ?: 'localhost';
    }
}
