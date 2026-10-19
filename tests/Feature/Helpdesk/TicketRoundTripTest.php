<?php

namespace Tests\Feature\Helpdesk;

use App\Mail\TicketReplied;
use App\Models\Ticket;
use App\Models\TicketEmailMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The full conversation loop:
 *
 *   requester emails in  →  ops replies from the web UI  →  requester hits
 *   Reply on that email  →  it lands back on the same ticket
 *
 * The second hop is the one that breaks in naive implementations: the
 * requester's client sets In-Reply-To to the Message-ID *we* generated, so if
 * outbound IDs aren't recorded, their reply opens a duplicate ticket.
 */
class TicketRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret = 'test-inbound-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function opsUser(): User
    {
        return User::forceCreate([
            'name' => 'Ops Agent',
            'email' => 'agent@mwmotor.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    protected function inbound(array $overrides = [])
    {
        $payload = array_merge([
            'From' => 'dana@mwmotor.com',
            'FromFull' => ['Email' => 'dana@mwmotor.com', 'Name' => 'Dana', 'MailboxHash' => ''],
            'To' => 'helpdesk@mwmotor.com',
            'Subject' => 'Scanner not working',
            'MessageID' => 'in-1@mail.example.com',
            'TextBody' => 'The scanner throws an error.',
            'Headers' => [['Name' => 'Message-ID', 'Value' => '<in-1@mail.example.com>']],
            'Attachments' => [],
        ], $overrides);

        return $this->postJson('/webhooks/inbound-email/'.$this->secret, $payload);
    }

    public function test_the_full_round_trip_stays_on_one_ticket(): void
    {
        Mail::fake();

        // 1. Requester emails in → ticket opened.
        $this->inbound()->assertOk();
        $ticket = Ticket::sole();
        $this->assertSame('Open', $ticket->status);

        // 2. Ops replies from the web UI.
        $this->actingAs($this->opsUser())
            ->post(route('ops.tickets.reply', $ticket), [
                'message' => 'Can you send a photo of the error?',
            ])
            ->assertRedirect();

        // The reply was emailed to the requester, from the help desk address,
        // with a Reply-To carrying this ticket's hash.
        Mail::assertSent(TicketReplied::class, function (TicketReplied $mail) use ($ticket) {
            $envelope = $mail->envelope();

            return $mail->hasTo('dana@mwmotor.com')
                && $envelope->from?->address === 'helpdesk@mwmotor.com'
                && str_contains($envelope->subject, $ticket->subjectTag());
        });

        // 3. The outbound Message-ID must be recorded — this is what the
        //    requester's client will reference.
        $outbound = TicketEmailMessage::where('direction', TicketEmailMessage::DIRECTION_OUTBOUND)
            ->whereNotNull('message_id')
            ->latest('id')
            ->first();

        $this->assertNotNull($outbound, 'Outbound Message-ID was not recorded.');

        // 4. Requester hits Reply — In-Reply-To points at OUR id, and the
        //    subject is the mangled thing a real client would produce.
        $this->inbound([
            'MessageID' => 'in-2@mail.example.com',
            'Subject' => 'RE: Scanner not working',
            'TextBody' => 'Photo attached.',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<in-2@mail.example.com>'],
                ['Name' => 'In-Reply-To', 'Value' => '<'.$outbound->message_id.'>'],
            ],
        ])->assertOk();

        // One ticket, three messages on it.
        $this->assertDatabaseCount('tickets', 1);

        $ticket->refresh();
        $this->assertCount(2, $ticket->replies);
        $this->assertSame('ops', $ticket->replies[0]->author_role);
        $this->assertSame('requester', $ticket->replies[1]->author_role);
        $this->assertStringContainsString('Photo attached', $ticket->replies[1]->message);
    }

    public function test_an_ops_reply_moves_an_open_ticket_to_in_progress(): void
    {
        Mail::fake();

        $this->inbound()->assertOk();
        $ticket = Ticket::sole();

        $this->actingAs($this->opsUser())
            ->post(route('ops.tickets.reply', $ticket), ['message' => 'Looking into it.'])
            ->assertRedirect();

        $this->assertSame('In Progress', $ticket->fresh()->status);
    }

    public function test_a_requester_reply_pulls_a_waiting_ticket_back_to_open(): void
    {
        Mail::fake();

        $this->inbound()->assertOk();
        $ticket = Ticket::sole();
        $ticket->update(['status' => 'Waiting']);

        $this->inbound([
            'MessageID' => 'in-3@mail.example.com',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<in-3@mail.example.com>'],
                ['Name' => 'In-Reply-To', 'Value' => '<in-1@mail.example.com>'],
            ],
        ])->assertOk();

        $this->assertSame('Open', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->last_requester_message_at);
    }

    public function test_the_outbound_reply_carries_threading_headers(): void
    {
        Mail::fake();

        $this->inbound()->assertOk();
        $ticket = Ticket::sole();

        // The auto-acknowledgement is itself an outbound message on this
        // thread, so it — not the requester's original — is the most recent
        // ancestor the ops reply should point In-Reply-To at.
        $ack = TicketEmailMessage::where('ticket_id', $ticket->id)
            ->where('direction', TicketEmailMessage::DIRECTION_OUTBOUND)
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($this->opsUser())
            ->post(route('ops.tickets.reply', $ticket), ['message' => 'On it.'])
            ->assertRedirect();

        Mail::assertSent(TicketReplied::class, function (TicketReplied $mail) use ($ack) {
            $headers = $mail->headers();

            return $headers->messageId !== null
                // In-Reply-To is the newest message on the thread (RFC 5322).
                && ($headers->text['In-Reply-To'] ?? null) === '<'.$ack->message_id.'>'
                // References carries the whole chain, original included, which
                // is what mail clients actually group a conversation on.
                && in_array('in-1@mail.example.com', $headers->references, true)
                && in_array($ack->message_id, $headers->references, true);
        });
    }

    public function test_the_outbound_reply_sets_a_plus_addressed_reply_to(): void
    {
        Mail::fake();

        $this->inbound()->assertOk();
        $ticket = Ticket::sole();

        $this->actingAs($this->opsUser())
            ->post(route('ops.tickets.reply', $ticket), ['message' => 'On it.'])
            ->assertRedirect();

        Mail::assertSent(TicketReplied::class, function (TicketReplied $mail) use ($ticket) {
            $replyTo = $mail->envelope()->replyTo[0] ?? null;

            // helpdesk+<id><token>@mwmotor.com — the third threading signal.
            return $replyTo?->address === 'helpdesk+'.$ticket->mailboxHash().'@mwmotor.com';
        });
    }
}
