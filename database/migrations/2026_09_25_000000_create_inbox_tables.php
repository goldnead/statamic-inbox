<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->string('name');
            $table->string('email');

            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 16)->default('ssl');
            $table->string('username');
            // Encrypted by the model's cast; ciphertext is far longer than the password.
            $table->text('password');

            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->string('smtp_encryption', 16)->default('tls');

            $table->string('inbox_folder')->default('INBOX');
            $table->string('sent_folder')->nullable();
            $table->boolean('append_sent')->default(true);

            $table->unsignedBigInteger('last_uid_inbox')->default(0);
            $table->unsignedBigInteger('last_uid_sent')->default(0);
            $table->timestamp('last_fetched_at')->nullable();
            $table->text('last_error')->nullable();

            $table->date('import_since')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('inbox_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->foreignId('mailbox_id')->constrained('inbox_mailboxes')->cascadeOnDelete();
            $table->string('subject')->default('');
            // Normalised (no Re/AW/Fwd, lower case) for the third threading rule.
            $table->string('subject_normalized')->default('');
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('counterpart_email')->default('');
            $table->string('status', 16)->default('open');
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('unread')->default(true);
            $table->timestamps();

            $table->index(['mailbox_id', 'counterpart_email', 'subject_normalized'], 'inbox_conv_subject_idx');
            $table->index(['brand_id', 'status', 'last_message_at'], 'inbox_conv_list_idx');
        });

        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->foreignId('mailbox_id')->constrained('inbox_mailboxes')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('inbox_conversations')->cascadeOnDelete();
            $table->string('direction', 3);
            // Without angle brackets. 191 characters keep the unique index
            // inside InnoDB's key length under utf8mb4.
            $table->string('message_id', 191);
            $table->string('in_reply_to', 191)->nullable();
            $table->text('references')->nullable();
            $table->string('from_email')->default('');
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->string('subject')->default('');
            $table->longText('text')->nullable();
            $table->longText('html_sanitized')->nullable();
            $table->longText('body_stripped')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('folder')->nullable();
            $table->unsignedBigInteger('imap_uid')->nullable();
            $table->boolean('has_remote_images')->default(false);
            // Set when a reply could not be sent; the text stays for a retry.
            $table->text('send_error')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'message_id']);
            $table->index('in_reply_to');
        });

        Schema::create('inbox_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->foreignId('message_id')->constrained('inbox_messages')->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime')->default('application/octet-stream');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_attachments');
        Schema::dropIfExists('inbox_messages');
        Schema::dropIfExists('inbox_conversations');
        Schema::dropIfExists('inbox_mailboxes');
    }
};
