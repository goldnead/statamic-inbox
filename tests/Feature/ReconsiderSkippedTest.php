<?php

/*
 * Review of v0.2.0, recovery and the reclassify minors.
 *
 * 0.2.0 could discard real leads: a contact-form mail from noreply@ with the
 * person in Reply-To, and replies that arrived in INBOX before their thread
 * was read from Sent. `inbox:reclassify --reconsider-skipped` looks at every
 * skip record again (headers by PEEK, found by UID, else by Message-ID in
 * INBOX and All Mail) and imports what no longer counts as bulk mail.
 */

use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
    $this->client = $this->imap->client($this->mailbox);
});

/** A skip record as 0.2.0 wrote it: plain Message-ID, the sender kept. */
function legacySkip($mailbox, string $folder, int $uid, string $messageId, string $sender, string $reason): SkippedMessage
{
    return SkippedMessage::create([
        'mailbox_id' => $mailbox->id, 'folder' => $folder, 'uid' => $uid,
        'message_id' => $messageId, 'sender' => $sender, 'reason' => $reason,
        'skipped_at' => now(),
    ]);
}

it('imports a contact-form mail that 0.2.0 skipped, after a dry run that changes nothing', function () {
    $uid = $this->client->deliver('INBOX', mailFixture('24-contact-form.eml'));
    $newsletter = $this->client->deliver('INBOX', mailFixture('07-html-tracking.eml'));
    legacySkip($this->mailbox, 'INBOX', $uid, 'form-24@goldner.test', 'noreply@goldner.test', 'noreply_sender');
    legacySkip($this->mailbox, 'INBOX', $newsletter, 'nl-2026-09-24.7781@mailer.shop.example', 'newsletter@shop.example', 'list_header');

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--reconsider-skipped' => true, '--dry-run' => true])
        ->expectsOutputToContain('skipped records checked: 2')
        ->expectsOutputToContain('to import: 1')
        ->expectsOutputToContain('still skipped: 1')
        ->assertSuccessful();

    expect(Message::count())->toBe(0)->and(SkippedMessage::count())->toBe(2);

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--reconsider-skipped' => true])
        ->expectsOutputToContain('imported: 1')
        ->assertSuccessful();

    expect(Conversation::sole()->counterpart_email)->toBe('paula.sopran@example.com')
        ->and(Conversation::sole()->status)->toBe('new')
        ->and(SkippedMessage::count())->toBe(1)
        // The record left is private now: hashed, no sender.
        ->and(SkippedMessage::sole()->message_id)->toBe('sha256:'.hash('sha256', 'nl-2026-09-24.7781@mailer.shop.example'))
        ->and(SkippedMessage::sole()->sender)->toBeNull();
});

it('finds a mail by its Message-ID in All Mail when its UID moved', function () {
    $this->client->allMailFolder = '[Gmail]/Alle Nachrichten';
    $uid = $this->client->deliver('[Gmail]/Alle Nachrichten', mailFixture('25-booking.eml'));
    // Recorded under an INBOX UID that no longer exists (archived).
    legacySkip($this->mailbox, 'INBOX', 9999, 'booking-25@termine.example', 'no-reply@termine.example', 'noreply_sender');

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--reconsider-skipped' => true])
        ->expectsOutputToContain('imported: 1')
        ->assertSuccessful();

    $message = Message::sole();

    expect($message->folder)->toBe('[Gmail]/Alle Nachrichten')
        ->and($message->imap_uid)->toBe($uid)
        ->and(SkippedMessage::count())->toBe(0);

    // Found by searching, never by changing anything on the server.
    expect(collect($this->client->calls)->pluck('method')->all())->not->toContain('append');
});

it('imports a reply that 0.2.0 skipped because INBOX was read before Sent', function () {
    $this->client->deliver('Sent', mailFixture('02-sent-reply.eml'));
    $uid = $this->client->deliver('INBOX', mailFixture('18-auto-reply-in-thread.eml'));
    app(Goldnead\StatamicInbox\Fetching\MailboxFetcher::class)->fetch($this->mailbox->fresh());
    // What 0.2.0 did to the auto-reply in the same fetch:
    Message::where('message_id', 'anna-ooo-18@mail.gmail.com')->delete();
    SkippedMessage::query()->delete();
    legacySkip($this->mailbox, 'INBOX', $uid, 'anna-ooo-18@mail.gmail.com', 'anna.beispiel@example.com', 'auto_submitted');

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--reconsider-skipped' => true])->assertSuccessful();

    expect(Message::where('message_id', 'anna-ooo-18@mail.gmail.com')->exists())->toBeTrue()
        ->and(Conversation::count())->toBe(1);
});

it('leaves records of hidden senders alone', function () {
    $uid = $this->client->deliver('INBOX', mailFixture('06-same-subject-other-sender.eml'));
    legacySkip($this->mailbox, 'INBOX', $uid, 'bob-20260923-1100@example.org', 'bob@example.org', 'blocked');

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--reconsider-skipped' => true])->assertSuccessful();

    expect(Message::count())->toBe(0)
        ->and(SkippedMessage::sole()->sender)->toBe('bob@example.org');
});

it('takes a new conversation out of Neu when it has become relevant', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);
    $conversation = Conversation::sole();
    expect($conversation->status)->toBe('new');

    Goldnead\Leadhub\Models\Contact::create(['email' => 'carla@example.net']);

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id])
        ->expectsOutputToContain('out of new: 1')
        ->assertSuccessful();

    expect($conversation->fresh()->status)->toBe('open');
});

it('still files a conversation by relevance when its headers cannot be read', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);
    Message::query()->update(['filter_headers' => null]);
    Conversation::query()->update(['status' => 'open']);
    $this->client->breakFolder('INBOX', new RuntimeException('archived'));

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id])->assertSuccessful();

    expect(Conversation::sole()->status)->toBe('new');
});
