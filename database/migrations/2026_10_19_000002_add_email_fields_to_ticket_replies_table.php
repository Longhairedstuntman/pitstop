<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            // 'web' | 'email' — how this reply reached us.
            $table->string('source', 20)->default('web')->after('author_role');

            // Display name from the mail client, when present.
            $table->string('author_name')->nullable()->after('author_email');

            // Original HTML body of an emailed reply. `message` always holds the
            // plain-text (quote-stripped) version; this is kept for fidelity.
            $table->text('message_html')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropColumn(['source', 'author_name', 'message_html']);
        });
    }
};
