<?php

/*
 * Spec test 1: the fetch. No duplicates by Message-ID, and new UIDs land in
 * the right conversation. The IMAP server is a fake fed with fixed .eml files.
 */

use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
});

it('imports a new message into a new open, unread conversation', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $message = Message::sole();
    $conversation = Conversation::sole();

    expect($message->conversation_id)->toBe($conversation->id)
        ->and($message->direction)->toBe('in')
        ->and($message->message_id)->toBe('CAanna001+x7Qk2@mail.gmail.com')
        ->and($message->from_email)->toBe('anna.beispiel@example.com')
        ->and($message->from_name)->toBe('Anna Beispiel')
        ->and($message->subject)->toBe('Frage zum Coaching')
        ->and($message->folder)->toBe('INBOX')
        ->and($message->imap_uid)->toBe(1)
        ->and($message->text)->toContain('in der Höhe die Luft')
        ->and($message->sent_at->equalTo(Carbon::parse('2026-09-20 07:00:00', 'UTC')))->toBeTrue();

    expect($conversation->mailbox_id)->toBe($this->mailbox->id)
        ->and($conversation->counterpart_email)->toBe('anna.beispiel@example.com')
        ->and($conversation->subject)->toBe('Frage zum Coaching')
        ->and($conversation->status)->toBe('open')
        ->and($conversation->unread)->toBeTrue()
        ->and($conversation->last_message_at->equalTo($message->sent_at))->toBeTrue();
});

it('remembers the last UID per folder and fetches nothing twice', function () {
    $client = deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    $mailbox = $this->mailbox->fresh();

    expect($mailbox->last_uid_inbox)->toBe(1)
        ->and($mailbox->last_uid_sent)->toBe(1)
        ->and($mailbox->last_fetched_at)->not->toBeNull()
        ->and($mailbox->last_error)->toBeNull();

    $client->calls = [];
    app(MailboxFetcher::class)->fetch($mailbox);

    expect(Message::count())->toBe(2)
        ->and(collect($client->calls)->where('method', 'fetchRaw'))->toBeEmpty();
});

it('skips a message whose Message-ID is already stored, even under a new UID', function () {
    $client = deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    // The same message again, e.g. moved out of a folder and back.
    $client->deliver('INBOX', mailFixture('01-new-thread.eml'));
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Message::count())->toBe(1)
        ->and(Conversation::count())->toBe(1)
        ->and($this->mailbox->fresh()->last_uid_inbox)->toBe(2);
});

it('derives a stable Message-ID from a hash when the header is missing, and dedupes on it', function () {
    $client = deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);

    $first = Message::sole();

    expect($first->message_id)->toBeString()->not->toBe('');

    $client->deliver('INBOX', mailFixture('08-no-message-id.eml'));
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Message::count())->toBe(1)
        ->and(Message::sole()->message_id)->toBe($first->message_id)
        ->and($first->text)->toContain('hätten Sie im Oktober');
});

it('stores Sent mail as outgoing and puts it in the same conversation as the mail it answers', function () {
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '03-gmail-reply.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    $conversation = Conversation::sole();

    expect($conversation->messages()->count())->toBe(3)
        ->and(Message::where('folder', 'Sent')->sole()->direction)->toBe('out')
        ->and(Message::where('direction', 'in')->count())->toBe(2)
        // The other side is Anna, never the mailbox itself, even though the
        // Sent message is From the mailbox.
        ->and($conversation->counterpart_email)->toBe('anna.beispiel@example.com');
});

it('lands a new UID arriving later in the conversation it belongs to', function () {
    $client = deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    $conversation = Conversation::sole();
    $conversation->update(['status' => 'waiting', 'unread' => false]);

    $client->deliver('INBOX', mailFixture('03-gmail-reply.eml'));
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    $conversation->refresh();

    expect(Conversation::count())->toBe(1)
        ->and($conversation->messages()->count())->toBe(3)
        ->and($conversation->status)->toBe('open')
        ->and($conversation->unread)->toBeTrue()
        ->and($this->mailbox->fresh()->last_uid_inbox)->toBe(2);
});

it('sets a conversation to waiting when the newest message is outgoing', function () {
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    expect(Conversation::sole()->status)->toBe('waiting');
});

it('ends a snooze when a new incoming message arrives', function () {
    $client = deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $conversation = Conversation::sole();
    $conversation->update(['snoozed_until' => Carbon::parse('2026-10-01 08:00:00'), 'unread' => false]);

    $client->deliver('INBOX', mailFixture('03-gmail-reply.eml'));
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    $conversation->refresh();

    expect($conversation->snoozed_until)->toBeNull()
        ->and($conversation->status)->toBe('open')
        ->and($conversation->unread)->toBeTrue();
});

it('imports the last 90 days on a first fetch by default', function () {
    expect($this->mailbox->fresh()->import_since->toDateString())->toBe('2026-06-27');
});

it('starts a first fetch at import_since and passes that date to the server', function () {
    $this->mailbox->update(['import_since' => Carbon::parse('2026-09-21')]);

    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '03-gmail-reply.eml'],
    ]);

    expect(Message::pluck('message_id')->all())->toBe(['CAanna003+Zr9Lm@mail.gmail.com']);
});

it('fetches every active mailbox from the inbox:fetch command and skips inactive ones', function () {
    $inactive = inboxMailbox(['email' => 'alt@goldner.test', 'username' => 'alt@goldner.test', 'active' => false]);

    $this->imap->client($this->mailbox)->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->imap->client($inactive)->deliver('INBOX', mailFixture('06-same-subject-other-sender.eml'));

    $this->artisan('inbox:fetch')->assertSuccessful();

    expect(Message::pluck('message_id')->all())->toBe(['CAanna001+x7Qk2@mail.gmail.com']);
});

it('keeps fetching the other mailboxes when one fails', function () {
    $broken = inboxMailbox(['email' => 'kaputt@goldner.test', 'username' => 'kaputt@goldner.test']);

    $this->imap->client($broken)->failWith(new RuntimeException('Connection refused'));
    $this->imap->client($this->mailbox)->deliver('INBOX', mailFixture('01-new-thread.eml'));

    $this->artisan('inbox:fetch');

    expect(Message::count())->toBe(1)
        ->and($broken->fresh()->last_error)->toContain('Connection refused');
});
