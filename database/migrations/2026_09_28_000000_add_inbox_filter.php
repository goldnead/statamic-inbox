<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only relevant mail (TASKS/inbox-filter-spec-2026-09-25.md): skipped bulk
 * mail as bare records, a blocklist per mailbox, own aliases, the headers the
 * filter decides on, and "accepted" as a reason a conversation is relevant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_mailboxes', function (Blueprint $table) {
            $table->boolean('skip_bulk')->default(true)->after('append_sent');
            $table->json('aliases')->nullable()->after('skip_bulk');
        });

        Schema::table('inbox_conversations', function (Blueprint $table) {
            // "Übernehmen": relevant without a CRM contact, and it stays so.
            $table->timestamp('accepted_at')->nullable()->after('unread');
        });

        Schema::table('inbox_messages', function (Blueprint $table) {
            // What the filter decided on, so a rule change can be re-applied
            // without asking the server again.
            $table->json('filter_headers')->nullable()->after('has_remote_images');
        });

        Schema::create('inbox_skipped_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->foreignId('mailbox_id')->constrained('inbox_mailboxes')->cascadeOnDelete();
            $table->string('folder', 191);
            $table->unsignedBigInteger('uid')->nullable();
            $table->string('message_id', 191);
            // The other side, so removing a block rule can find what it held back.
            $table->string('sender')->nullable();
            $table->string('reason', 32);
            $table->timestamp('skipped_at');
            $table->timestamps();

            $table->unique(['mailbox_id', 'message_id']);
            $table->index(['mailbox_id', 'reason', 'skipped_at']);
        });

        Schema::create('inbox_block_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->foreignId('mailbox_id')->constrained('inbox_mailboxes')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('value', 191);
            $table->timestamps();

            $table->unique(['mailbox_id', 'type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_block_rules');
        Schema::dropIfExists('inbox_skipped_messages');

        Schema::table('inbox_messages', fn (Blueprint $table) => $table->dropColumn('filter_headers'));
        Schema::table('inbox_conversations', fn (Blueprint $table) => $table->dropColumn('accepted_at'));
        Schema::table('inbox_mailboxes', fn (Blueprint $table) => $table->dropColumn(['skip_bulk', 'aliases']));
    }
};
