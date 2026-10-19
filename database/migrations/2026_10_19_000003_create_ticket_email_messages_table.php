<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index of every RFC 5322 Message-ID we have seen or sent for a ticket.
     *
     * This is what makes email threading work: when a reply arrives, its
     * In-Reply-To / References headers are looked up here to find the ticket
     * the conversation already belongs to. Outbound messages are recorded too,
     * because a requester replying to one of our emails will reference OUR
     * Message-ID, not one of theirs.
     *
     * It doubles as the idempotency guard — `message_id` is unique, so a
     * webhook delivered twice cannot create the ticket or reply twice.
     */
    public function up(): void
    {
        Schema::create('ticket_email_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();

            // Null for the message that opened the ticket; otherwise the reply
            // this email produced (or was produced by).
            $table->foreignId('ticket_reply_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            // 'inbound' = we received it, 'outbound' = we sent it.
            $table->string('direction', 10);

            // Message-ID with angle brackets stripped and lowercased.
            //
            // RFC 5322 permits longer, but real-world Message-IDs are well under
            // 255 chars and this has to stay indexable: 255 * 4 bytes (utf8mb4)
            // sits comfortably under MySQL's 3072-byte key limit. Anything longer
            // is truncated on write by InboundEmailThreader.
            $table->string('message_id', 255)->nullable()->unique();
            $table->string('in_reply_to', 255)->nullable()->index();

            // Full References chain — not indexed, parsed in PHP.
            $table->text('references')->nullable();

            $table->string('from_email')->nullable();
            $table->string('subject', 1000)->nullable();

            // Provider payload, retained for debugging a misrouted thread.
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->index(['ticket_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_email_messages');
    }
};
