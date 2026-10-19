<?php

namespace App\Mail;

use App\Mail\Concerns\ThreadsTicketEmail;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketReplied extends Mailable
{
    use Queueable, SerializesModels, ThreadsTicketEmail;

    public function __construct(
        public Ticket $ticket,
        public TicketReply $reply,
        public string $recipientType = 'requester',
        public ?string $outboundMessageId = null,
        public ?string $inReplyTo = null,
        public array $references = [],
    ) {}

    public function envelope(): Envelope
    {
        $isOps = $this->recipientType === 'ops';

        if ($isOps) {
            // Internal notification: no threading, no help desk From — this
            // goes to the ops inbox, and its replies are not requester replies.
            return new Envelope(
                subject: "New reply on request #{$this->ticket->id}: {$this->ticket->subject}",
            );
        }

        return new Envelope(
            from: $this->helpdeskFrom(),
            replyTo: $this->helpdeskReplyTo($this->ticket),
            subject: $this->ticket->taggedSubject(
                "Re: {$this->ticket->subject}"
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket_replied',
            with: [
                'recipientType' => $this->recipientType,
            ],
        );
    }
}
