<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After the review of b441eb7: per-folder state (UIDVALIDITY, errors), the
 * failed-UID ledger, full values for ids too long to index, the APPEND
 * outcome on a reply, and Content-IDs for inline images.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_mailboxes', function (Blueprint $table) {
            $table->unsignedBigInteger('uidvalidity_inbox')->nullable()->after('last_uid_sent');
            $table->unsignedBigInteger('uidvalidity_sent')->nullable()->after('uidvalidity_inbox');
            // mailbox | folder | message: how much of the mailbox the last error takes down.
            $table->string('last_error_scope', 16)->nullable()->after('last_error');
            $table->json('folder_errors')->nullable()->after('last_error_scope');
        });

        Schema::table('inbox_messages', function (Blueprint $table) {
            // Set only when the Message-ID is longer than the indexed column
            // and `message_id` therefore holds a hash of it.
            $table->text('message_id_full')->nullable()->after('message_id');
            // The reply went out but could not be filed into Sent.
            $table->text('filed_error')->nullable()->after('send_error');
        });

        Schema::table('inbox_attachments', function (Blueprint $table) {
            $table->string('content_id', 191)->nullable()->after('mime');
        });

        Schema::create('inbox_fetch_failures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->foreignId('mailbox_id')->constrained('inbox_mailboxes')->cascadeOnDelete();
            $table->string('folder', 191);
            $table->unsignedBigInteger('uid');
            $table->text('error');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('gave_up_at')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'folder', 'uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_fetch_failures');

        Schema::table('inbox_attachments', fn (Blueprint $table) => $table->dropColumn('content_id'));
        Schema::table('inbox_messages', fn (Blueprint $table) => $table->dropColumn(['message_id_full', 'filed_error']));
        Schema::table('inbox_mailboxes', fn (Blueprint $table) => $table->dropColumn([
            'uidvalidity_inbox', 'uidvalidity_sent', 'last_error_scope', 'folder_errors',
        ]));
    }
};
