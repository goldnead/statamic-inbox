<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.2.2: signatures per mailbox, and a mark on mails the website sent itself
 * through the mailbox (order confirmations, invoices), which never make a
 * conversation relevant (TASKS/inbox-0-2-2-spec-2026-09-27.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_mailboxes', function (Blueprint $table) {
            // [{id, name, body, default, tags}], in the order the tag rules are checked.
            $table->json('signatures')->nullable()->after('aliases');
        });

        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->boolean('automatic')->default(false)->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_messages', fn (Blueprint $table) => $table->dropColumn('automatic'));
        Schema::table('inbox_mailboxes', fn (Blueprint $table) => $table->dropColumn('signatures'));
    }
};
