<?php

namespace App\Services\Helpdesk;

use App\Mail\Inbound\InboundAttachment;
use App\Mail\Inbound\InboundEmail;
use App\Mail\TicketReplied;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketEmailMessage;
use App\Models\TicketReply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns a received email into a new ticket or a reply on an existing one.
 *
 * The entry point is `process()`. It is safe to call twice with the same
 * message — the unique index on `ticket_email_messages.message_id` makes
 * duplicate webhook deliveries a no-op.
 */
class InboundEmailProcessor
{
    public function __construct(
        protected InboundEmailThreader $threader,
        protected OutboundTicketMailer $mailer,
    ) {}

    /**
     * @return array{action: string, ticket: ?Ticket, reason: ?string}
     */
    public function process(InboundEmail $email): array
    {
        // --- Guards, cheapest and most important first --------------------

        if ($this->alreadyProcessed($email)) {
            return $this->result('duplicate', null, 'Message-ID already recorded.');
        }

        if ($this->isBlockedSender($email)) {
            Log::info('Helpdesk: dropped mail from blocked sender', [
                'from' => $email->fromEmail,
                'subject' => $email->subject,
            ]);

            return $this->result('ignored', null, 'Blocked or machine sender.');
        }

        if ($email->isAutomated()) {
            Log::info('Helpdesk: dropped automated message', [
                'from' => $email->fromEmail,
                'subject' => $email->subject,
            ]);

            return $this->result('ignored', null, 'Automated message (auto-reply, bounce or list mail).');
        }

        // --- Route it -----------------------------------------------------

        $ticket = $this->threader->resolve($email);

        if ($ticket && $this->shouldReopen($ticket)) {
            return $this->appendReply($email, $ticket);
        }

        if ($ticket && !$ticket->isClosed()) {
            return $this->appendReply($email, $ticket);
        }

        // Either no match, or a match too old to reopen: start a new ticket.
        if ($rateLimited = $this->rateLimitedTicketFor($email)) {
            Log::warning('Helpdesk: sender over rate limit, appending instead of opening', [
                'from' => $email->fromEmail,
                'ticket_id' => $rateLimited->id,
            ]);

            return $this->appendReply($email, $rateLimited);
        }

        return $this->openTicket($email);
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    protected function alreadyProcessed(InboundEmail $email): bool
    {
        $messageId = TicketEmailMessage::normaliseId($email->messageId);

        if ($messageId === null) {
            return false;
        }

        return TicketEmailMessage::where('message_id', $messageId)->exists();
    }

    /**
     * Blocks our own address (loop prevention), anything explicitly listed,
     * and the usual machine local-parts.
     */
    protected function isBlockedSender(InboundEmail $email): bool
    {
        $from = mb_strtolower(trim($email->fromEmail));

        if ($from === '' || !str_contains($from, '@')) {
            return true;
        }

        $inbound = mb_strtolower((string) config('helpdesk.inbound_address'));
        if ($inbound !== '' && $from === $inbound) {
            return true;
        }

        // Also block plus-addressed variants of our own mailbox.
        if ($inbound !== '' && str_contains($inbound, '@')) {
            [$mailbox, $domain] = explode('@', $inbound, 2);
            if (preg_match('/^'.preg_quote($mailbox, '/').'\+[^@]*@'.preg_quote($domain, '/').'$/i', $from)) {
                return true;
            }
        }

        foreach ((array) config('helpdesk.blocked_senders', []) as $blocked) {
            if ($from === mb_strtolower(trim($blocked))) {
                return true;
            }
        }

        $localPart = mb_strtolower(explode('@', $from)[0]);
        foreach ((array) config('helpdesk.blocked_sender_prefixes', []) as $prefix) {
            if ($localPart === mb_strtolower($prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A closed ticket reopens on reply only inside the configured window.
     */
    protected function shouldReopen(Ticket $ticket): bool
    {
        if (!$ticket->isClosed()) {
            return false;
        }

        $window = (int) config('helpdesk.reopen_window_days', 30);

        if ($window <= 0) {
            return true;
        }

        return $ticket->updated_at !== null
            && $ticket->updated_at->gte(now()->subDays($window));
    }

    /**
     * When a sender floods the address, funnel the overflow into their most
     * recent ticket rather than creating hundreds.
     */
    protected function rateLimitedTicketFor(InboundEmail $email): ?Ticket
    {
        $limit = (int) config('helpdesk.rate_limit_per_sender_per_hour', 20);

        if ($limit <= 0) {
            return null;
        }

        $recentCount = Ticket::where('requester_email', $email->fromEmail)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentCount < $limit) {
            return null;
        }

        return Ticket::where('requester_email', $email->fromEmail)
            ->orderByDesc('id')
            ->first();
    }

    // -----------------------------------------------------------------
    // Actions
    // -----------------------------------------------------------------

    protected function openTicket(InboundEmail $email): array
    {
        $category = (string) config('helpdesk.email_default_category', 'General');
        $priority = (string) config('helpdesk.email_default_priority', 'Normal');

        $ticket = DB::transaction(function () use ($email, $category, $priority) {
            $ticket = Ticket::create([
                'requester_email' => $email->fromEmail,
                'requester_name' => $email->fromName,
                'subject' => $email->cleanSubject(),
                'description' => $email->bestTextBody(),
                'category' => $category,
                'priority' => $priority,
                'status' => 'Open',
                'source' => Ticket::SOURCE_EMAIL,
                'last_requester_message_at' => now(),
            ]);

            $this->recordInboundMessage($email, $ticket, null);
            $this->storeAttachments($email, $ticket, $ticket);

            return $ticket;
        });

        $this->notifyOpsOfNewTicket($ticket);
        $this->sendAcknowledgement($ticket);

        Log::info('Helpdesk: ticket opened from email', [
            'ticket_id' => $ticket->id,
            'from' => $email->fromEmail,
        ]);

        return $this->result('created', $ticket);
    }

    protected function appendReply(InboundEmail $email, Ticket $ticket): array
    {
        $reply = DB::transaction(function () use ($email, $ticket) {
            $reply = $ticket->replies()->create([
                'author_role' => TicketReply::ROLE_REQUESTER,
                'author_email' => $email->fromEmail,
                'author_name' => $email->fromName,
                'message' => $email->bestTextBody(),
                'message_html' => $email->htmlBody,
                'source' => TicketReply::SOURCE_EMAIL,
            ]);

            $this->recordInboundMessage($email, $ticket, $reply);
            $this->storeAttachments($email, $ticket, $reply);

            $updates = ['last_requester_message_at' => now()];

            // A requester reply on a closed ticket reopens it; on an open one
            // it moves back to Open so it resurfaces in the ops queue.
            if ($ticket->isClosed() || $ticket->status === 'Waiting') {
                $updates['status'] = 'Open';
            }

            $ticket->update($updates);

            return $reply;
        });

        $this->notifyOpsOfReply($ticket, $reply);

        Log::info('Helpdesk: reply appended from email', [
            'ticket_id' => $ticket->id,
            'reply_id' => $reply->id,
            'from' => $email->fromEmail,
        ]);

        return $this->result('replied', $ticket->fresh());
    }

    // -----------------------------------------------------------------
    // Persistence helpers
    // -----------------------------------------------------------------

    protected function recordInboundMessage(InboundEmail $email, Ticket $ticket, ?TicketReply $reply): void
    {
        TicketEmailMessage::create([
            'ticket_id' => $ticket->id,
            'ticket_reply_id' => $reply?->id,
            'direction' => TicketEmailMessage::DIRECTION_INBOUND,
            'message_id' => TicketEmailMessage::normaliseId($email->messageId),
            'in_reply_to' => TicketEmailMessage::normaliseId($email->inReplyTo),
            'references' => $email->references,
            'from_email' => $email->fromEmail,
            'subject' => $email->subject ? mb_substr($email->subject, 0, 1000) : null,
            'raw_payload' => $email->rawPayload,
        ]);
    }

    /**
     * @param  Ticket|TicketReply  $attachable
     */
    protected function storeAttachments(InboundEmail $email, Ticket $ticket, $attachable): void
    {
        $disk = (string) config('helpdesk.attachment_disk', 'local');
        $maxBytes = (int) config('helpdesk.attachment_max_bytes', 10 * 1024 * 1024);

        foreach ($email->attachments as $attachment) {
            if (!$attachment instanceof InboundAttachment) {
                continue;
            }

            if ($maxBytes > 0 && $attachment->size() > $maxBytes) {
                Log::warning('Helpdesk: inbound attachment too large, dropped', [
                    'ticket_id' => $ticket->id,
                    'name' => $attachment->safeName(),
                    'size' => $attachment->size(),
                ]);

                continue;
            }

            $safeName = $attachment->safeName();
            $path = "helpdesk/tickets/{$ticket->id}/".Str::uuid().'-'.$safeName;

            try {
                Storage::disk($disk)->put($path, $attachment->content);
            } catch (\Throwable $e) {
                Log::error('Helpdesk: failed storing inbound attachment', [
                    'ticket_id' => $ticket->id,
                    'name' => $safeName,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'attachable_type' => $attachable::class,
                'attachable_id' => $attachable->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $safeName,
                'mime_type' => $attachment->contentType,
                'size' => $attachment->size(),
                'content_id' => $attachment->contentId,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Outbound notifications
    // -----------------------------------------------------------------

    protected function sendAcknowledgement(Ticket $ticket): void
    {
        if (!config('helpdesk.send_auto_acknowledgement', true)) {
            return;
        }

        // Goes through the mailer so the acknowledgement's own Message-ID is
        // recorded — a requester who replies to the acknowledgement rather
        // than to a later message must still thread correctly.
        $this->mailer->sendAcknowledgement($ticket);
    }

    protected function notifyOpsOfNewTicket(Ticket $ticket): void
    {
        $opsEmail = config('helpdesk.ops_notify_email');

        if (blank($opsEmail)) {
            return;
        }

        try {
            Mail::to($opsEmail)->send(new \App\Mail\NewTicketSubmitted($ticket));
        } catch (\Throwable $e) {
            Log::error('Helpdesk: ops new-ticket notification failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function notifyOpsOfReply(Ticket $ticket, TicketReply $reply): void
    {
        $opsEmail = config('helpdesk.ops_notify_email');

        if (blank($opsEmail)) {
            return;
        }

        try {
            Mail::to($opsEmail)->send(new TicketReplied($ticket, $reply, 'ops'));
        } catch (\Throwable $e) {
            Log::error('Helpdesk: ops reply notification failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function result(string $action, ?Ticket $ticket, ?string $reason = null): array
    {
        return [
            'action' => $action,
            'ticket' => $ticket,
            'reason' => $reason,
        ];
    }
}
