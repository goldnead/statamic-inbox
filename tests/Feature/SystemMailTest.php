<?php

/*
 * 0.2.2, item 2: mails the website sent itself through the same mailbox
 * (order and booking confirmations, invoices, access mails) are system
 * mails. They make no conversation relevant, do not set it to "waiting" and
 * do not make the recipient a known correspondent. Inside a conversation
 * that exists for other reasons they stay visible, marked as automatic.
 *
 * Recognised, in this order: an automation header; our own and Gmail's
 * Message-IDs are never system mail, a Symfony/Laravel Message-ID on a mail
 * that answers nothing is; the subject patterns from the config.
 */

use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Filtering\SystemMailDetector;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;
use Goldnead\StatamicInbox\Sending\ReplySender;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
});

function fetchRaw($imap, $mailbox, string $folder, string $raw): void
{
    $imap->client($mailbox)->deliver($folder, $raw);
    app(MailboxFetcher::class)->fetch($mailbox->fresh());
}

it('makes no relevant conversation of an order confirmation, but a personal mail to the same person afterwards does', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['27-sent-order-confirmation.eml']]);

    expect(Conversation::count())->toBe(0)
        ->and(SkippedMessage::sole()->reason)->toBe('system');

    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['28-sent-personal-to-tina.eml']]);

    $conversation = Conversation::sole();

    expect($conversation->counterpart_email)->toBe('tina.testkauf@example.com')
        ->and($conversation->status)->toBe('waiting');
});

it('does not make the buyer a known correspondent', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['27-sent-order-confirmation.eml']]);
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['29-tina-reply-to-order.eml']]);

    // Her question is kept, as a first contact in "Neu".
    expect(Conversation::sole()->status)->toBe('new');
});

it('shows a system mail inside a conversation that exists anyway, marked and without changing its status', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);
    expect(Conversation::sole()->status)->toBe('new');

    fetchRaw($this->imap, $this->mailbox, 'Sent', systemMail('booking-1@goldner.test', 'Deine Buchung: Probestunde', [
        'In-Reply-To' => '<bob-20260923-1100@example.org>',
        'Auto-Submitted' => 'auto-generated',
    ], 'bob@example.org'));

    $conversation = Conversation::sole();
    $system = Message::where('message_id', 'booking-1@goldner.test')->sole();

    expect($system->automatic)->toBeTrue()
        ->and($system->conversation_id)->toBe($conversation->id)
        ->and($conversation->status)->toBe('new')
        ->and($conversation->unread)->toBeTrue();
});

it('does not set an open conversation to waiting', function () {
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '03-gmail-reply.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);
    expect(Conversation::sole()->status)->toBe('open');

    fetchRaw($this->imap, $this->mailbox, 'Sent', systemMail('access-1@goldner.test', 'Dein Zugang zum Kurs', [
        'In-Reply-To' => '<CAanna003+Zr9Lm@mail.gmail.com>',
        'X-Auto-Response-Suppress' => 'All',
    ], 'anna.beispiel@example.com', 'Tue, 22 Sep 2026 10:00:00 +0200'));

    expect(Conversation::sole()->status)->toBe('open')
        ->and(Conversation::sole()->messages()->count())->toBe(4);
});

it('recognises system mail by header, Message-ID and subject, in that order', function (array $headers, string $messageId, string $subject, bool $expected) {
    $detector = app(SystemMailDetector::class);

    $names = array_map('strtolower', array_keys($headers));
    $filter = [
        'header_names' => $names,
        'auto_submitted' => isset($headers['Auto-Submitted']) ? strtolower($headers['Auto-Submitted']) : null,
    ];

    expect($detector->isSystem($filter, $messageId, $subject, $headers['In-Reply-To'] ?? null))->toBe($expected);
})->with([
    'Auto-Submitted' => [['Auto-Submitted' => 'auto-generated'], 'x1@goldner.test', 'Hallo', true],
    'Auto-Submitted: no' => [['Auto-Submitted' => 'no'], 'x2@goldner.test', 'Hallo', false],
    'X-Auto-Response-Suppress' => [['X-Auto-Response-Suppress' => 'OOF'], 'x3@goldner.test', 'Hallo', true],
    'X-Suite header' => [['X-Suite-Mail' => 'invoice'], 'x4@goldner.test', 'Hallo', true],
    'own reply from the inbox' => [[], 'inbox.8a1d2c4e-1111-2222-3333-444455556666@goldner.test', 'Deine Rechnung 17', false],
    'Gmail webmail' => [[], 'CAxyz+abc@mail.gmail.com', 'Deine Bestellung: Kurs', false],
    'Symfony Message-ID, answering nothing' => [[], '4f1c2a9be07d3b58c6a1e2f0d9b87c34@goldner.test', 'Hallo', true],
    'Symfony Message-ID, answering someone' => [['In-Reply-To' => 'CAanna@mail.gmail.com'], '4f1c2a9be07d3b58c6a1e2f0d9b87c34@goldner.test', 'Re: Hallo', false],
    'subject pattern' => [[], 'adrian-1@goldner.test', 'Deine Rechnung 2026-17', true],
    'subject pattern, case and prefix' => [[], 'adrian-2@goldner.test', 'deine buchung: Probestunde', true],
    'personal mail' => [[], 'adrian-3@goldner.test', 'Probe am Donnerstag', false],
]);

it('takes the subject patterns from the config', function () {
    config()->set('inbox.system_mail.subjects', ['Ihre Rechnung']);
    $detector = app(SystemMailDetector::class);

    expect($detector->isSystem([], 'a@goldner.test', 'Ihre Rechnung 17', null))->toBeTrue()
        ->and($detector->isSystem([], 'b@goldner.test', 'Deine Rechnung 17', null))->toBeFalse();
});

it('never takes a reply sent from the inbox for a system mail', function () {
    fakeSmtp();
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);

    app(ReplySender::class)->send(Conversation::sole(), 'Deine Rechnung kommt morgen.');
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Message::where('direction', 'out')->sole()->automatic)->toBeFalse()
        ->and(Conversation::sole()->status)->toBe('waiting');
});

it('marks a system mail as automatic in the conversation page', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);
    fetchRaw($this->imap, $this->mailbox, 'Sent', systemMail('booking-2@goldner.test', 'Deine Buchung: Probestunde', [
        'In-Reply-To' => '<bob-20260923-1100@example.org>',
    ], 'bob@example.org', 'Wed, 23 Sep 2026 12:00:00 +0200'));

    $response = $this->actingAs(inboxCpUser(['view inbox']))
        ->get(cp_route('inbox.conversations.show', Conversation::sole()->id));
    $response->assertOk();
    $messages = $response->viewData('page')['props']['messages'];

    expect(array_column($messages, 'automatic'))->toBe([false, true]);
});
