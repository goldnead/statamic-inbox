<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Skip records keep as little as possible (review of 0.2.0): the sender only
 * where a hidden-sender rule needs it.
 *
 * The plain Message-IDs 0.2.0 stored are left for now:
 * `inbox:reclassify --reconsider-skipped` needs them to find a mail whose
 * UID has moved, and hashes every record at the end of its real run. New
 * records are hashed from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inbox_skipped_messages')
            ->where('reason', '!=', 'blocked')
            ->update(['sender' => null]);
    }

    public function down(): void
    {
        // The senders are gone on purpose; nothing to restore.
    }
};
