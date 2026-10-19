<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Short random token used to thread email replies back to this ticket
            // (subject tag + plus-addressing hash). Not secret enough to be the only
            // auth factor, but it makes subject-tag matching non-guessable.
            $table->string('public_token', 32)->nullable()->after('id');

            // Where the ticket came in from: 'web' | 'email'
            $table->string('source', 20)->default('web')->after('status');

            // Display name of the requester, when the mail client supplied one.
            $table->string('requester_name')->nullable()->after('requester_email');

            // Bumped whenever the requester sends a message, so ops can sort by
            // "waiting on us" without walking the replies relation.
            $table->timestamp('last_requester_message_at')->nullable()->after('source');
        });

        // Backfill tokens for tickets that already exist.
        DB::table('tickets')->whereNull('public_token')->orderBy('id')->each(function ($ticket) {
            DB::table('tickets')
                ->where('id', $ticket->id)
                ->update(['public_token' => Str::lower(Str::random(12))]);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unique('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn([
                'public_token',
                'source',
                'requester_name',
                'last_requester_message_at',
            ]);
        });
    }
};
