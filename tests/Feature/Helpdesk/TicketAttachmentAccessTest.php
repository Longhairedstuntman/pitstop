<?php

namespace Tests\Feature\Helpdesk;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Attachments arrive from unauthenticated senders and can contain anything.
 * These tests pin down who is allowed to read them.
 */
class TicketAttachmentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Ticket $ticket;

    protected TicketAttachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->ticket = Ticket::create([
            'requester_email' => 'requester@mwmotor.com',
            'subject' => 'Broken keyboard',
            'description' => 'Keys stick.',
            'category' => 'General',
            'priority' => 'Normal',
            'status' => 'Open',
        ]);

        Storage::disk('local')->put('helpdesk/tickets/1/secret.txt', 'contents');

        $this->attachment = TicketAttachment::create([
            'ticket_id' => $this->ticket->id,
            'attachable_type' => Ticket::class,
            'attachable_id' => $this->ticket->id,
            'disk' => 'local',
            'path' => 'helpdesk/tickets/1/secret.txt',
            'original_name' => 'secret.txt',
            'mime_type' => 'text/plain',
            'size' => 8,
        ]);
    }

    protected function user(string $email, string $role = 'user'): User
    {
        return User::forceCreate([
            'name' => 'Test User',
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    public function test_a_guest_cannot_download_an_attachment(): void
    {
        $this->get(route('ticket_attachments.show', $this->attachment))
            ->assertRedirect(route('login'));
    }

    public function test_the_requester_can_download_their_own_attachment(): void
    {
        $this->actingAs($this->user('requester@mwmotor.com'))
            ->get(route('ticket_attachments.show', $this->attachment))
            ->assertOk();
    }

    public function test_an_unrelated_user_cannot_download_the_attachment(): void
    {
        $this->actingAs($this->user('someone-else@mwmotor.com'))
            ->get(route('ticket_attachments.show', $this->attachment))
            ->assertForbidden();
    }

    public function test_ops_can_download_any_attachment(): void
    {
        $this->actingAs($this->user('ops@mwmotor.com', 'ops'))
            ->get(route('ticket_attachments.show', $this->attachment))
            ->assertOk();
    }

    public function test_the_attachment_is_served_as_a_download_not_inline(): void
    {
        $response = $this->actingAs($this->user('ops@mwmotor.com', 'ops'))
            ->get(route('ticket_attachments.show', $this->attachment));

        $response->assertOk();

        // Never render sender-supplied content inline on our own origin.
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    public function test_a_missing_file_returns_404_rather_than_an_error(): void
    {
        Storage::disk('local')->delete($this->attachment->path);

        $this->actingAs($this->user('ops@mwmotor.com', 'ops'))
            ->get(route('ticket_attachments.show', $this->attachment))
            ->assertNotFound();
    }
}
