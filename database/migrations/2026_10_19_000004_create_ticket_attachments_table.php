<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attachment metadata for both tickets and replies, from web uploads and
     * from inbound email alike.
     *
     * Files live on a PRIVATE disk and are served through a gated controller.
     * The previous behaviour wrote uploads to the `public` disk, which meant
     * anyone with the URL could read them — fine for a screenshot of a login
     * page, not fine for the invoices and ID photos that end up on an inbound
     * help desk.
     */
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();

            // Polymorphic: attaches to a Ticket (opening message) or a TicketReply.
            $table->morphs('attachable');

            // Denormalised so attachment access can be authorised without
            // loading the parent, and so deleting a ticket clears its files.
            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // Content-ID for inline images referenced from an HTML body.
            $table->string('content_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
