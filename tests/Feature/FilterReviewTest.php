<?php

/*
 * Review of v0.1.1..v0.2.0: four ways the filter could silently hide or
 * delete a real lead, and the privacy of the skip records.
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\BlockRule;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;
use Goldnead\StatamicInbox\Sending\ReplySender;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
    $this->user = inboxCpUser(['view inbox', 'reply inbox']);
});

// ── 1. Hiding never deletes a relevant conversation ─────────────────────

it('hides a sender without deleting a conversation you answered, or one with a contact', function () {
    fakeSmtp();
    // Two first contacts from the same domain, one of them answered, and one
    // from a LeadHub contact there.
    $bob = str_replace('bob@example.org', 'bob@chor.example', mailFixture('06-same-subject-other-sender.eml'));
    $alice = str_replace(['bob@example.org', 'bob-20260923-1100'], ['alice@chor.example', 'alice-1'], mailFixture('06-same-subject-other-sender.eml'));
    $carl = str_replace(['bob@example.org', 'bob-20260923-1100'], ['carl@chor.example', 'carl-1'], mailFixture('06-same-subject-other-sender.eml'));
    Contact::create(['email' => 'carl@chor.example']);

    $client = $this->imap->client($this->mailbox);
    foreach ([$bob, $alice, $carl] as $raw) {
        $client->deliver('INBOX', $raw);
    }
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    $answered = Conversation::where('counterpart_email', 'alice@chor.example')->sole();
    app(ReplySender::class)->send($answered, 'Gern!');
    $ourReply = Message::where('conversation_id', $answered->id)->where('direction', 'out')->sole();

    $firstContact = Conversation::where('counterpart_email', 'bob@chor.example')->sole();
    expect($firstContact->status)->toBe('new');

    // The dialog is told how many will go before anything happens.
    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.$firstContact->id.'/block', ['scope' => 'domain', 'preview' => true])
        ->assertOk()
        ->assertJson(['count' => 1]);
    expect(BlockRule::count())->toBe(0);

    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.$firstContact->id.'/block', ['scope' => 'domain'])
        ->assertSuccessful()
        ->assertJson(['deleted' => 1]);

    expect(Conversation::find($firstContact->id))->toBeNull()
        ->and(Conversation::find($answered->id))->not->toBeNull()
        ->and(Message::find($ourReply->id))->not->toBeNull()
        ->and(Conversation::where('counterpart_email', 'carl@chor.example')->exists())->toBeTrue();
});

it('refuses to hide from a conversation that is not a first contact', function () {
    Contact::create(['email' => 'bob@example.org']);
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);

    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.Conversation::sole()->id.'/block', ['scope' => 'sender'])
        ->assertStatus(422);

    expect(BlockRule::count())->toBe(0)->and(Conversation::count())->toBe(1);
});

it('lets a contact through although the sender is hidden', function () {
    BlockRule::create(['mailbox_id' => $this->mailbox->id, 'type' => 'domain', 'value' => 'example.com']);
    Contact::create(['email' => 'anna.beispiel@example.com']);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    expect(Conversation::count())->toBe(1);
});

it('lets a known correspondent through although the domain is hidden', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['21-sent-to-bob.eml']]);
    BlockRule::create(['mailbox_id' => $this->mailbox->id, 'type' => 'domain', 'value' => 'example.org']);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);

    expect(Message::where('message_id', 'bob-20260923-1100@example.org')->exists())->toBeTrue();
});

// ── 2. The thread exception does not depend on the folder order ─────────

it('reads Sent before INBOX, so an auto-reply to your mail finds its thread in one fetch', function () {
    // Adrian wrote first (Sent), Anna's out-of-office answers it (INBOX,
    // bulk by its headers). Both arrive in the same fetch.
    $client = $this->imap->client($this->mailbox);
    $client->deliver('INBOX', mailFixture('18-auto-reply-in-thread.eml'));
    $client->deliver('Sent', mailFixture('02-sent-reply.eml'));

    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Message::where('message_id', 'anna-ooo-18@mail.gmail.com')->exists())->toBeTrue()
        ->and(SkippedMessage::count())->toBe(0);
});

it('names a conversation opened by your reply without the Re:', function () {
    // With Sent read first, your reply can be the first message stored.
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    expect(Conversation::sole()->subject)->toBe('Frage zum Coaching');
});

it('stores bulk-looking mail from someone this mailbox wrote to before', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['21-sent-to-bob.eml']]);
    $raw = str_replace('Subject: Frage zum Coaching', "Subject: Frage zum Coaching\nAuto-Submitted: auto-replied", mailFixture('06-same-subject-other-sender.eml'));
    $this->imap->client($this->mailbox)->deliver('INBOX', $raw);

    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Message::where('message_id', 'bob-20260923-1100@example.org')->exists())->toBeTrue();
});

// ── 3. Reply-To carries the real person ─────────────────────────────────

it('keeps a contact-form mail from noreply@ and makes the Reply-To the counterpart', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['24-contact-form.eml']]);

    $conversation = Conversation::sole();

    expect(SkippedMessage::count())->toBe(0)
        ->and($conversation->counterpart_email)->toBe('paula.sopran@example.com')
        ->and($conversation->status)->toBe('new');
});

it('keeps a booking-tool mail and answers the person who booked', function () {
    fakeSmtp();
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['25-booking.eml']]);

    $conversation = Conversation::sole();
    $out = app(ReplySender::class)->send($conversation, 'Bis Donnerstag!');

    expect($conversation->counterpart_email)->toBe('tom.bariton@example.net')
        ->and($out->to[0]['email'])->toBe('tom.bariton@example.net');
});

it('links a contact-form mail to the LeadHub contact in Reply-To', function () {
    $contact = Contact::create(['email' => 'paula.sopran@example.com']);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['24-contact-form.eml']]);

    expect(Conversation::sole()->contact_id)->toBe($contact->id)
        ->and(Conversation::sole()->status)->toBe('open');
});

it('still skips a no-reply mail whose Reply-To is no-reply too', function () {
    $raw = str_replace('Reply-To: Paula Sopran <paula.sopran@example.com>', 'Reply-To: <do-not-reply@goldner.test>', mailFixture('24-contact-form.eml'));
    $this->imap->client($this->mailbox)->deliver('INBOX', $raw);

    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(SkippedMessage::sole()->reason)->toBe('noreply_sender');
});

// ── 4. Skip records keep as little as possible ──────────────────────────

it('stores only a hash of the Message-ID, and the sender only for hidden senders', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['07-html-tracking.eml']]);

    $skip = SkippedMessage::sole();

    expect($skip->message_id)->toBe('sha256:'.hash('sha256', 'nl-2026-09-24.7781@mailer.shop.example'))
        ->and($skip->sender)->toBeNull();

    // And the dedupe still holds.
    $this->imap->client($this->mailbox)->deliver('INBOX', mailFixture('07-html-tracking.eml'));
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(SkippedMessage::count())->toBe(1)->and(Message::count())->toBe(0);
});

it('keeps the sender on a skip record of a hidden sender, so removing the rule finds it', function () {
    BlockRule::create(['mailbox_id' => $this->mailbox->id, 'type' => 'sender', 'value' => 'bob@example.org']);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);

    expect(SkippedMessage::sole()->only(['reason', 'sender']))->toBe(['reason' => 'blocked', 'sender' => 'bob@example.org']);
});

// ── 5. Removing a rule takes the same permission as setting it ──────────

it('lets whoever may hide a sender also remove the rule', function () {
    $rule = BlockRule::create(['mailbox_id' => $this->mailbox->id, 'type' => 'sender', 'value' => 'bob@example.org']);

    $this->actingAs($this->user)
        ->deleteJson('/cp/inbox/mailboxes/'.$this->mailbox->id.'/rules/'.$rule->id)
        ->assertSuccessful();

    expect(BlockRule::count())->toBe(0);
});
