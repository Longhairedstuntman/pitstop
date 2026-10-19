<?php

namespace Tests\Feature\Helpdesk;

use App\Mail\TicketAcknowledged;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketEmailMessage;
use App\Models\TicketReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundEmailTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret = 'test-inbound-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
    }

    /**
     * Build a Postmark-shaped inbound payload.
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'From' => 'tech@mwmotor.com',
            'FromName' => 'Dana Tech',
            'FromFull' => [
                'Email' => 'tech@mwmotor.com',
                'Name' => 'Dana Tech',
                'MailboxHash' => '',
            ],
            'To' => 'helpdesk@mwmotor.com',
            'ToFull' => [
                ['Email' => 'helpdesk@mwmotor.com', 'Name' => '', 'MailboxHash' => ''],
            ],
            'OriginalRecipient' => 'helpdesk@mwmotor.com',
            'Subject' => 'Printer on 2nd floor is offline',
            'MessageID' => 'inbound-msg-0001@mail.example.com',
            'MailboxHash' => '',
            'TextBody' => "The printer by the sales desk won't come online.\n\nThanks,\nDana",
            'HtmlBody' => '<p>The printer by the sales desk won\'t come online.</p>',
            'StrippedTextReply' => '',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<inbound-msg-0001@mail.example.com>'],
            ],
            'Attachments' => [],
        ], $overrides);
    }

    protected function postInbound(array $payload, ?string $secret = null)
    {
        return $this->postJson(
            '/webhooks/inbound-email/'.($secret ?? $this->secret),
            $payload
        );
    }

    // -----------------------------------------------------------------
    // Endpoint security
    // -----------------------------------------------------------------

    public function test_webhook_rejects_a_bad_secret(): void
    {
        $this->postInbound($this->payload(), 'wrong-secret')
            ->assertForbidden();

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_webhook_accepts_the_correct_secret(): void
    {
        $this->postInbound($this->payload())->assertOk();
    }

    public function test_webhook_requires_no_csrf_token(): void
    {
        // Posting without any session or token must still succeed — a mail
        // provider cannot present one.
        $this->postInbound($this->payload())->assertOk();
        $this->assertDatabaseCount('tickets', 1);
    }

    // -----------------------------------------------------------------
    // Opening tickets
    // -----------------------------------------------------------------

    public function test_an_email_opens_a_ticket(): void
    {
        $this->postInbound($this->payload())->assertOk();

        $ticket = Ticket::sole();

        $this->assertSame('tech@mwmotor.com', $ticket->requester_email);
        $this->assertSame('Dana Tech', $ticket->requester_name);
        $this->assertSame('Printer on 2nd floor is offline', $ticket->subject);
        $this->assertSame('Open', $ticket->status);
        $this->assertSame(Ticket::SOURCE_EMAIL, $ticket->source);
        $this->assertStringContainsString("won't come online", $ticket->description);
        $this->assertNotEmpty($ticket->public_token);
    }

    public function test_opening_a_ticket_records_the_inbound_message_id(): void
    {
        $this->postInbound($this->payload())->assertOk();

        $this->assertDatabaseHas('ticket_email_messages', [
            'message_id' => 'inbound-msg-0001@mail.example.com',
            'direction' => TicketEmailMessage::DIRECTION_INBOUND,
        ]);
    }

    public function test_an_acknowledgement_is_sent_to_the_requester(): void
    {
        $this->postInbound($this->payload())->assertOk();

        Mail::assertSent(TicketAcknowledged::class, function ($mail) {
            return $mail->hasTo('tech@mwmotor.com');
        });
    }

    public function test_the_subject_is_stripped_of_reply_prefixes(): void
    {
        $this->postInbound($this->payload([
            'Subject' => 'RE: FW: Re: Printer is offline',
        ]))->assertOk();

        $this->assertSame('Printer is offline', Ticket::sole()->subject);
    }

    // -----------------------------------------------------------------
    // Threading
    // -----------------------------------------------------------------

    public function test_a_reply_threads_onto_the_existing_ticket_via_in_reply_to(): void
    {
        $this->postInbound($this->payload())->assertOk();
        $ticket = Ticket::sole();

        $this->postInbound($this->payload([
            'MessageID' => 'inbound-msg-0002@mail.example.com',
            'Subject' => 'Re: Printer on 2nd floor is offline',
            'TextBody' => 'It is still down this morning.',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<inbound-msg-0002@mail.example.com>'],
                ['Name' => 'In-Reply-To', 'Value' => '<inbound-msg-0001@mail.example.com>'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 1);
        $this->assertCount(1, $ticket->fresh()->replies);
        $this->assertSame(
            TicketReply::ROLE_REQUESTER,
            $ticket->fresh()->replies->first()->author_role
        );
    }

    public function test_a_reply_threads_via_the_references_chain(): void
    {
        $this->postInbound($this->payload())->assertOk();

        $this->postInbound($this->payload([
            'MessageID' => 'inbound-msg-0003@mail.example.com',
            'Subject' => 'Re: something the user retyped',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<inbound-msg-0003@mail.example.com>'],
                // No In-Reply-To, only References — common on forwards.
                ['Name' => 'References', 'Value' => '<unknown@x.com> <inbound-msg-0001@mail.example.com>'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 1);
        $this->assertCount(1, Ticket::sole()->replies);
    }

    public function test_a_reply_threads_via_the_subject_tag(): void
    {
        $this->postInbound($this->payload())->assertOk();
        $ticket = Ticket::sole();

        $this->postInbound($this->payload([
            'MessageID' => 'inbound-msg-0004@mail.example.com',
            // No threading headers at all — only the tag survives.
            'Subject' => 'Re: Printer '.$ticket->subjectTag(),
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<inbound-msg-0004@mail.example.com>'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 1);
        $this->assertCount(1, $ticket->fresh()->replies);
    }

    public function test_a_reply_threads_via_the_plus_addressed_mailbox_hash(): void
    {
        $this->postInbound($this->payload())->assertOk();
        $ticket = Ticket::sole();

        $this->postInbound($this->payload([
            'MessageID' => 'inbound-msg-0005@mail.example.com',
            'Subject' => 'a completely different subject',
            'MailboxHash' => $ticket->mailboxHash(),
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<inbound-msg-0005@mail.example.com>'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 1);
        $this->assertCount(1, $ticket->fresh()->replies);
    }

    public function test_a_subject_tag_with_the_wrong_token_does_not_match(): void
    {
        $this->postInbound($this->payload())->assertOk();
        $ticket = Ticket::sole();

        // Right ticket id, forged token — must not graft onto the ticket.
        $this->postInbound($this->payload([
            'MessageID' => 'inbound-msg-0006@mail.example.com',
            'Subject' => 'Unrelated request [#PS-'.$ticket->id.'-deadbeef1234]',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<inbound-msg-0006@mail.example.com>'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 2);
    }

    public function test_an_unrelated_email_opens_a_second_ticket(): void
    {
        $this->postInbound($this->payload())->assertOk();

        $this->postInbound($this->payload([
            'MessageID' => 'inbound-msg-0007@mail.example.com',
            'Subject' => 'Need a new monitor',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<inbound-msg-0007@mail.example.com>'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 2);
    }

    // -----------------------------------------------------------------
    // Idempotency and loop protection
    // -----------------------------------------------------------------

    public function test_a_duplicate_delivery_does_not_create_a_second_ticket(): void
    {
        $this->postInbound($this->payload())->assertOk();
        $this->postInbound($this->payload())->assertOk();

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_email_messages', 2); // 1 inbound + 1 ack
    }

    public function test_mail_from_the_helpdesk_itself_is_ignored(): void
    {
        $this->postInbound($this->payload([
            'From' => 'helpdesk@mwmotor.com',
            'FromFull' => ['Email' => 'helpdesk@mwmotor.com', 'Name' => 'Help Desk', 'MailboxHash' => ''],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_an_out_of_office_auto_reply_is_ignored(): void
    {
        $this->postInbound($this->payload([
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<ooo@mail.example.com>'],
                ['Name' => 'Auto-Submitted', 'Value' => 'auto-replied'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_a_bulk_precedence_message_is_ignored(): void
    {
        $this->postInbound($this->payload([
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<newsletter@mail.example.com>'],
                ['Name' => 'Precedence', 'Value' => 'bulk'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_mail_from_a_no_reply_address_is_ignored(): void
    {
        $this->postInbound($this->payload([
            'From' => 'no-reply@vendor.com',
            'FromFull' => ['Email' => 'no-reply@vendor.com', 'Name' => 'Vendor', 'MailboxHash' => ''],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_a_mailer_daemon_bounce_is_ignored(): void
    {
        $this->postInbound($this->payload([
            'From' => 'MAILER-DAEMON@mail.example.com',
            'FromFull' => ['Email' => 'MAILER-DAEMON@mail.example.com', 'Name' => '', 'MailboxHash' => ''],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 0);
    }

    // -----------------------------------------------------------------
    // Attachments
    // -----------------------------------------------------------------

    public function test_attachments_are_stored_privately_and_recorded(): void
    {
        $this->postInbound($this->payload([
            'Attachments' => [
                [
                    'Name' => 'screenshot.png',
                    'Content' => base64_encode('fake-png-bytes'),
                    'ContentType' => 'image/png',
                    'ContentLength' => 14,
                ],
            ],
        ]))->assertOk();

        $attachment = TicketAttachment::sole();

        $this->assertSame('screenshot.png', $attachment->original_name);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists($attachment->path);

        // Must NOT be on a publicly readable path.
        $this->assertStringStartsWith('helpdesk/tickets/', $attachment->path);
    }

    public function test_a_malicious_attachment_filename_cannot_escape_the_directory(): void
    {
        $this->postInbound($this->payload([
            'Attachments' => [
                [
                    'Name' => '../../../../.env',
                    'Content' => base64_encode('pwned'),
                    'ContentType' => 'text/plain',
                    'ContentLength' => 5,
                ],
            ],
        ]))->assertOk();

        $attachment = TicketAttachment::sole();

        $this->assertStringNotContainsString('..', $attachment->path);
        $this->assertStringStartsWith('helpdesk/tickets/', $attachment->path);
        $this->assertSame('env', $attachment->original_name);
    }

    public function test_an_oversized_attachment_is_dropped(): void
    {
        config(['helpdesk.attachment_max_bytes' => 10]);

        $this->postInbound($this->payload([
            'Attachments' => [
                [
                    'Name' => 'big.bin',
                    'Content' => base64_encode(str_repeat('x', 500)),
                    'ContentType' => 'application/octet-stream',
                    'ContentLength' => 500,
                ],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_attachments', 0);
    }

    // -----------------------------------------------------------------
    // Status transitions
    // -----------------------------------------------------------------

    public function test_a_reply_reopens_a_recently_closed_ticket(): void
    {
        $this->postInbound($this->payload())->assertOk();
        $ticket = Ticket::sole();
        $ticket->update(['status' => 'Closed']);

        $this->postInbound($this->payload([
            'MessageID' => 'inbound-msg-0008@mail.example.com',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<inbound-msg-0008@mail.example.com>'],
                ['Name' => 'In-Reply-To', 'Value' => '<inbound-msg-0001@mail.example.com>'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame('Open', $ticket->fresh()->status);
    }

    public function test_a_reply_to_a_long_closed_ticket_opens_a_new_one(): void
    {
        config(['helpdesk.reopen_window_days' => 30]);

        $this->postInbound($this->payload())->assertOk();
        $ticket = Ticket::sole();
        $ticket->status = 'Closed';
        $ticket->save();
        // Push it outside the reopen window.
        $ticket->updated_at = now()->subDays(90);
        $ticket->saveQuietly();

        $this->postInbound($this->payload([
            'MessageID' => 'inbound-msg-0009@mail.example.com',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<inbound-msg-0009@mail.example.com>'],
                ['Name' => 'In-Reply-To', 'Value' => '<inbound-msg-0001@mail.example.com>'],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('tickets', 2);
    }
}
