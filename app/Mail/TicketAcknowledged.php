<?php

namespace App\Mail;

use App\Mail\Concerns\ThreadsTicketEmail;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We received your email, here's your ticket number."
 *
 * Sent once, when a ticket is opened from an inbound email. Web-submitted
 * tickets don't get this — the submitter already saw a confirmation on screen.
 */
class TicketAcknowledged extends Mailable
{
    use Queueable, SerializesModels, ThreadsTicketEmail;

    public function __construct(
        public Ticket $ticket,
        public ?string $outboundMessageId = null,
        public ?string $inReplyTo = null,
        public array $references = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->helpdeskFrom(),
            replyTo: $this->helpdeskReplyTo($this->ticket),
            subject: $this->ticket->taggedSubject(
                "Received: {$this->ticket->subject}"
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket_acknowledged',
        );
    }
}
