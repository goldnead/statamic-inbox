<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name replies are sent under. `name` is the label in the CP list
 * ("Coaching") and must never reach a customer's From line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_mailboxes', function (Blueprint $table) {
            $table->string('from_name')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_mailboxes', fn (Blueprint $table) => $table->dropColumn('from_name'));
    }
};
