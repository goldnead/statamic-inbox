<?php

/*
 * Filter spec, stage 2, tests 5, 6 and 9: a conversation is relevant when the
 * other side is a LeadHub contact, when it has an outgoing message, or when
 * this mailbox has written to that address before. Everything else is "new":
 * first contacts, shown in their own tab, not counted in the menu.
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Goldnead\StatamicInbox\ServiceProvider;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
});

it('files a first contact from an unknown person as new', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);

    expect(Conversation::sole()->status)->toBe('new')
        ->and(Conversation::sole()->unread)->toBeTrue();
});

it('opens a conversation with a LeadHub contact', function () {
    Contact::create(['email' => 'carla@example.net']);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);

    expect(Conversation::sole()->status)->toBe('open');
});

it('treats a conversation you answered as relevant', function () {
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '03-gmail-reply.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    // Newest is Anna's answer: open, not new.
    expect(Conversation::sole()->status)->toBe('open');
});

it('opens a new conversation with someone this mailbox has written to before', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['21-sent-to-bob.eml']]);
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);

    $bob = Conversation::where('subject', 'Frage zum Coaching')->sole();

    expect($bob->status)->toBe('open')
        ->and(Conversation::count())->toBe(2);
});

it('moves a new conversation to waiting when you reply', function () {
    fakeSmtp();
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);
    $conversation = Conversation::sole();
    expect($conversation->status)->toBe('new');

    app(ReplySender::class)->send($conversation, 'Gern, im Oktober geht es.');

    expect($conversation->fresh()->status)->toBe('waiting');
});

it('keeps an answered conversation relevant when the next mail comes in', function () {
    fakeSmtp();
    $client = deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);
    app(ReplySender::class)->send(Conversation::sole(), 'Gern, im Oktober geht es.');

    $answer = "In-Reply-To: <".Conversation::sole()->messages()->where('direction', 'out')->value('message_id').">\n"
        .str_replace(['Subject: Termin?', 'Date: Thu, 24 Sep 2026 16:45:00 +0200'], ['Subject: Re: Termin?', 'Date: Fri, 25 Sep 2026 08:00:00 +0200'], mailFixture('08-no-message-id.eml'));
    $client->deliver('INBOX', $answer);
    app(Goldnead\StatamicInbox\Fetching\MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Conversation::sole()->status)->toBe('open');
});

it('counts only relevant unread conversations in the menu', function () {
    Contact::create(['email' => 'anna.beispiel@example.com']);

    // Anna (contact, unread), Carla (new, unread), Bob (new, unread).
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '08-no-message-id.eml', '06-same-subject-other-sender.eml'],
    ]);

    expect(Conversation::where('unread', true)->count())->toBe(3);

    $nav = new class
    {
        public array $items = [];

        public function create(string $name): object
        {
            return $this->items[] = new class($name)
            {
                public array $calls = [];

                public function __construct(public string $name) {}

                public function __call($method, $arguments)
                {
                    $this->calls[$method] = $arguments[0] ?? null;

                    return $this;
                }
            };
        }
    };

    $this->navCallbacks = [];
    $this->app->getProvider(ServiceProvider::class)->registerNavigation();
    ($this->navCallbacks[0])($nav);

    expect($nav->items[0]->name)->toBe('Postfach (1)')
        ->and($nav->items[0]->calls['attributes'])->toBe(['data-inbox-unread' => 1]);
});
