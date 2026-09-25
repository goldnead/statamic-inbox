<?php

/*
 * Filter spec (TASKS/inbox-filter-spec-2026-09-25.md), stage 1, tests 1 to 4
 * and 10: bulk mail is not stored at all, only a skip record that keeps the
 * dedupe working and the number visible. Contacts and replies in an existing
 * conversation always win. Sent mail counts as bulk only when it went to many.
 * Mail to yourself makes no conversation.
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
});

it('skips a mail with List-Unsubscribe, records it, and does not record it twice', function () {
    $client = deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['07-html-tracking.eml']]);

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0)
        ->and(Attachment::count())->toBe(0);

    $skip = SkippedMessage::sole();

    expect($skip->mailbox_id)->toBe($this->mailbox->id)
        ->and($skip->folder)->toBe('INBOX')
        ->and($skip->uid)->toBe(1)
        ->and($skip->reason)->toBe('list_header')
        // Only a hash of the id (review of 0.2.0).
        ->and($skip->message_id)->toBe(SkippedMessage::keyFor('nl-2026-09-24.7781@mailer.shop.example'))
        ->and($skip->skipped_at)->not->toBeNull();

    // The same mail under a new UID, and a second fetch.
    $client->deliver('INBOX', mailFixture('07-html-tracking.eml'));
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(SkippedMessage::count())->toBe(1)
        ->and(Message::count())->toBe(0)
        ->and($this->mailbox->fresh()->last_uid_inbox)->toBe(2);
});

it('skips each kind of bulk mail with its reason', function (string $fixture, string $reason) {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => [$fixture]]);

    expect(Message::count())->toBe(0)
        ->and(SkippedMessage::sole()->reason)->toBe($reason);
})->with([
    'List-Id' => ['10-list-id.eml', 'list_header'],
    'List-Post' => ['11-list-post.eml', 'list_header'],
    'Precedence: bulk' => ['12-precedence-bulk.eml', 'precedence'],
    'Auto-Submitted' => ['13-auto-submitted.eml', 'auto_submitted'],
    'Feedback-ID and X-SES-Outgoing' => ['14-feedback-id.eml', 'bulk_sender_header'],
    'X-Mailchimp and X-MC-User' => ['15-mailchimp.eml', 'bulk_sender_header'],
    'no-reply sender, any case' => ['16-noreply.eml', 'noreply_sender'],
    'bounce with an empty Return-Path' => ['17-bounce.eml', 'bounce'],
]);

it('stores a mail with Auto-Submitted: no', function () {
    $raw = str_replace('Auto-Submitted: auto-generated', 'Auto-Submitted: no', mailFixture('13-auto-submitted.eml'));
    $this->imap->client($this->mailbox)->deliver('INBOX', $raw);
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Message::count())->toBe(1)->and(SkippedMessage::count())->toBe(0);
});

it('takes extra bulk headers from the config', function () {
    config()->set('inbox.filter.bulk_headers', ['X-Vereinsmailer']);
    $raw = str_replace('Subject: Frage zum Coaching', "Subject: Frage zum Coaching\nX-Vereinsmailer: 3.1", mailFixture('06-same-subject-other-sender.eml'));
    $this->imap->client($this->mailbox)->deliver('INBOX', $raw);
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(SkippedMessage::sole()->reason)->toBe('bulk_sender_header');
});

it('stores bulk mail when the mailbox has skipping switched off', function () {
    $this->mailbox->update(['skip_bulk' => false]);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['07-html-tracking.eml', '12-precedence-bulk.eml']]);

    expect(Message::count())->toBe(2)->and(SkippedMessage::count())->toBe(0);
});

it('stores a newsletter from a LeadHub contact', function () {
    Contact::create(['email' => 'newsletter@shop.example']);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['07-html-tracking.eml']]);

    expect(Message::count())->toBe(1)->and(SkippedMessage::count())->toBe(0);
});

it('stores an auto-reply that belongs to an existing conversation', function () {
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['18-auto-reply-in-thread.eml']]);

    expect(Message::where('message_id', 'anna-ooo-18@mail.gmail.com')->exists())->toBeTrue()
        ->and(Conversation::count())->toBe(1)
        ->and(SkippedMessage::count())->toBe(0);
});

it('skips a sent mail to more than ten people, keeps one to a single person', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['19-sent-rundmail.eml', '21-sent-to-bob.eml']]);

    expect(Message::pluck('message_id')->all())->toBe(['adrian-bob-21@goldner.test'])
        ->and(SkippedMessage::sole()->reason)->toBe('mass_outgoing');
});

it('skips a sent mail that went out by Bcc list', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['20-sent-bcc-list.eml']]);

    expect(Message::count())->toBe(0)
        ->and(SkippedMessage::sole()->reason)->toBe('mass_outgoing');
});

it('makes no conversation for a mail to yourself', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['22-self.eml']]);

    expect(Conversation::count())->toBe(0)
        ->and(SkippedMessage::sole()->reason)->toBe('self');
});

it('treats an alias as your own address', function () {
    $this->mailbox->update(['aliases' => ['kontakt@goldner.test']]);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['23-alias.eml']]);

    expect(Conversation::count())->toBe(0)
        ->and(SkippedMessage::sole()->reason)->toBe('self');
});

it('keeps a mail from the same address when it is not an alias of this mailbox', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['23-alias.eml']]);

    expect(Conversation::sole()->counterpart_email)->toBe('kontakt@goldner.test');
});

it('never uses an own address as the counterpart of a mail from outside', function () {
    $this->mailbox->update(['aliases' => ['kontakt@goldner.test']]);
    $raw = str_replace('To: adrian@goldner.test', 'To: kontakt@goldner.test', mailFixture('06-same-subject-other-sender.eml'));
    $this->imap->client($this->mailbox)->deliver('INBOX', $raw);
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Conversation::sole()->counterpart_email)->toBe('bob@example.org');
});

it('stores the headers the filter needs with every message', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);

    $headers = Message::sole()->filter_headers;

    expect($headers)->toBeArray()
        ->toHaveKey('return_path')
        ->toHaveKey('recipients');
});
