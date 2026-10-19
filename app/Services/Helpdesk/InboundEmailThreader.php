<?php

namespace App\Services\Helpdesk;

use App\Mail\Inbound\InboundEmail;
use App\Models\Ticket;
use App\Models\TicketEmailMessage;

/**
 * Decides which existing ticket (if any) an inbound email belongs to.
 *
 * Four strategies, strongest evidence first. Each is independently sufficient;
 * the fallbacks exist because mail clients mangle headers constantly — Outlook
 * drops References on forwards, some mobile clients rewrite Message-IDs, and
 * webmail users routinely compose a "reply" as a brand new message.
 */
class InboundEmailThreader
{
    public function resolve(InboundEmail $email): ?Ticket
    {
        return $this->byHeaders($email)
            ?? $this->byMailboxHash($email)
            ?? $this->bySubjectTag($email)
            ?? $this->byRecentSenderThread($email);
    }

    /**
     * 1. In-Reply-To / References against every Message-ID we've recorded.
     *
     * This is the only strategy that is reliable in both directions, and the
     * reason outbound Message-IDs are stored: a requester replying to our
     * notification references OUR id, which appears in no inbound message.
     */
    protected function byHeaders(InboundEmail $email): ?Ticket
    {
        $candidates = [];

        if ($id = TicketEmailMessage::normaliseId($email->inReplyTo)) {
            $candidates[] = $id;
        }

        // References is ordered oldest-first; reverse so the most recent
        // ancestor wins when a thread has been forwarded between tickets.
        foreach (array_reverse(TicketEmailMessage::parseReferences($email->references)) as $id) {
            $candidates[] = $id;
        }

        if ($candidates === []) {
            return null;
        }

        $candidates = array_values(array_unique($candidates));

        $matches = TicketEmailMessage::query()
            ->whereIn('message_id', $candidates)
            ->get()
            ->keyBy('message_id');

        if ($matches->isEmpty()) {
            return null;
        }

        // Walk the candidates in priority order (In-Reply-To first, then the
        // References chain newest-first) and take the first one we know about.
        // Done in PHP rather than a CASE expression so this stays portable
        // across SQLite, MySQL and Postgres.
        foreach ($candidates as $candidate) {
            if ($match = $matches->get($candidate)) {
                return $match->ticket;
            }
        }

        return null;
    }

    /**
     * 2. Plus-addressing: helpdesk+412abc123@... → ticket 412.
     *
     * Survives header mangling because it rides in the envelope recipient.
     */
    protected function byMailboxHash(InboundEmail $email): ?Ticket
    {
        return Ticket::findByMailboxHash($email->mailboxHash);
    }

    /**
     * 3. The "[#PS-412-abc123]" tag in the subject line.
     *
     * Last resort among the deterministic strategies: subjects get edited and
     * forwarded around, but the token means a wrong id can't be guessed into.
     */
    protected function bySubjectTag(InboundEmail $email): ?Ticket
    {
        return Ticket::findBySubjectTag($email->subject);
    }

    /**
     * 4. Heuristic: same sender, identical cleaned subject, open ticket,
     *    within the last 7 days.
     *
     * Covers the common case of someone composing a fresh email with the same
     * subject instead of replying. Deliberately narrow — it requires an exact
     * subject match AND an open ticket AND a recent one, because a false
     * positive here silently buries a new request inside an old ticket.
     */
    protected function byRecentSenderThread(InboundEmail $email): ?Ticket
    {
        $subject = $email->cleanSubject();

        if ($subject === '(no subject)') {
            return null;
        }

        return Ticket::query()
            ->where('requester_email', $email->fromEmail)
            ->where('subject', $subject)
            ->whereNotIn('status', Ticket::CLOSED_STATUSES)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('id')
            ->first();
    }
}
