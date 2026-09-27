<?php

/*
 * 0.2.2, item 3: `inbox:reclassify` applies items 1 and 2 to what 0.2.1
 * imported. Conversations made only of system mails or only of mails to your
 * own (plus) addresses are deleted like bulk mail, with skip records; a
 * system mail in a conversation that stays is marked as automatic. The dry
 * run changes nothing.
 */

use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;
use Goldnead\StatamicInbox\Parsing\MessageParser;
use Goldnead\StatamicInbox\Support\MessageIds;

/**
 * Put a mail on the fake server and store it the way 0.2.1 did: into its own
 * conversation (or the given one), without stored headers.
 */
function importAsBefore($client, Mailbox $mailbox, string $folder, string $raw, string $counterpart, ?Conversation $conversation = null): Message
{
    $uid = $client->deliver($folder, $raw);
    $parsed = app(MessageParser::class)->parse($raw);

    $conversation ??= Conversation::create([
        'mailbox_id' => $mailbox->id,
        'subject' => $parsed->subject,
        'counterpart_email' => $counterpart,
        'status' => 'waiting',
        'unread' => false,
        'last_message_at' => $parsed->sentAt,
    ]);

    return Message::create([
        'mailbox_id' => $mailbox->id,
        'conversation_id' => $conversation->id,
        'direction' => $folder === 'Sent' ? 'out' : 'in',
        'message_id' => MessageIds::key($parsed->messageId),
        'in_reply_to' => $parsed->inReplyTo,
        'references' => implode(' ', $parsed->references) ?: null,
        'from_email' => $parsed->fromEmail,
        'subject' => $parsed->subject,
        'text' => $parsed->text,
        'body_stripped' => $parsed->text,
        'sent_at' => $parsed->sentAt,
        'folder' => $folder,
        'imap_uid' => $uid,
    ]);
}

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
    $this->client = $this->imap->client($this->mailbox);

    // Anna: a real conversation, stays as it is.
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '03-gmail-reply.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    // Tina: only the order confirmation.
    importAsBefore($this->client, $this->mailbox, 'Sent', mailFixture('27-sent-order-confirmation.eml'), 'tina.testkauf@example.com');

    // A test send to your own plus address.
    importAsBefore($this->client, $this->mailbox, 'Sent', mailFixture('26-sent-plus-address.eml'), 'adrian+nl-test-1@goldner.test');

    // Bob wrote; the website answered with a booking confirmation. 0.2.1
    // took that for your answer and set the conversation to waiting.
    $bob = importAsBefore($this->client, $this->mailbox, 'INBOX', mailFixture('06-same-subject-other-sender.eml'), 'bob@example.org');
    importAsBefore($this->client, $this->mailbox, 'Sent', systemMail('booking-3@goldner.test', 'Deine Buchung: Probestunde', [
        'In-Reply-To' => '<bob-20260923-1100@example.org>',
        'Auto-Submitted' => 'auto-generated',
    ], 'bob@example.org'), 'bob@example.org', $bob->conversation);

    Message::query()->update(['filter_headers' => null]);
});

it('counts on a dry run and changes nothing', function () {
    $before = [Message::count(), Conversation::count(), SkippedMessage::count()];

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--dry-run' => true])
        ->expectsOutputToContain('only system mails: 1')
        ->expectsOutputToContain('to yourself: 1')
        ->expectsOutputToContain('marked as automatic: 2')
        ->assertSuccessful();

    expect([Message::count(), Conversation::count(), SkippedMessage::count()])->toBe($before)
        ->and(Message::where('automatic', true)->count())->toBe(0);
});

it('never deletes your own mail from a webmail or one that answers by References only', function () {
    // Roundcube: a 32-hex Message-ID like Symfony's, and a subject like an invoice's.
    importAsBefore($this->client, $this->mailbox, 'Sent', mailFixture('30-sent-roundcube-personal.eml'), 'carla@example.net');

    // No In-Reply-To, only References: an answer, as the fetch reads it.
    $referencesOnly = str_replace(
        ['Message-ID: <9b2e4c7a1f0d38e65a4b2c1d0e9f8a7b@goldner.test>', 'User-Agent: Roundcube Webmail/1.6.9', 'To: Carla Beispiel <carla@example.net>'],
        ["Message-ID: <7c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f@goldner.test>\nReferences: <CAjonas001@mail.gmail.com>", 'X-Nothing: 1', 'To: Jonas Adler <jonas@example.net>'],
        mailFixture('30-sent-roundcube-personal.eml'),
    );
    importAsBefore($this->client, $this->mailbox, 'Sent', $referencesOnly, 'jonas@example.net');

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id])->assertSuccessful();

    expect(Conversation::where('counterpart_email', 'carla@example.net')->exists())->toBeTrue()
        ->and(Conversation::where('counterpart_email', 'jonas@example.net')->exists())->toBeTrue()
        ->and(Message::where('message_id', '9b2e4c7a1f0d38e65a4b2c1d0e9f8a7b@goldner.test')->sole()->automatic)->toBeFalse()
        ->and(Message::where('message_id', '7c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f@goldner.test')->sole()->automatic)->toBeFalse();
});

it('deletes system-only and own-address conversations and marks the rest', function () {
    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id])->assertSuccessful();

    expect(Conversation::pluck('counterpart_email')->sort()->values()->all())->toBe(['anna.beispiel@example.com', 'bob@example.org'])
        ->and(SkippedMessage::pluck('reason')->sort()->values()->all())->toBe(['self', 'system']);

    $bob = Conversation::where('counterpart_email', 'bob@example.org')->sole();

    expect(Message::where('message_id', 'booking-3@goldner.test')->sole()->automatic)->toBeTrue()
        // Only the website answered Bob: a first contact, not waiting.
        ->and($bob->status)->toBe('new')
        ->and(Conversation::where('counterpart_email', 'anna.beispiel@example.com')->sole()->status)->toBe('open');

    // A second run finds nothing more to do.
    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--dry-run' => true])
        ->expectsOutputToContain('only system mails: 0')
        ->expectsOutputToContain('to yourself: 0')
        ->expectsOutputToContain('set to new: 0')
        ->assertSuccessful();
});
