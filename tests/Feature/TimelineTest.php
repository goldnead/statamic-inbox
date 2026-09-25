<?php

/*
 * Spec test 8: the LeadHub timeline is written only when the contact already
 * exists, and then exactly once per message (dedupeKey `inbox:{message_id}`).
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event as TimelineEvent;
use Goldnead\StatamicInbox\Events\InboxMessageReceived;
use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
});

function inboxTimeline(): Builder
{
    return TimelineEvent::query()->where('dedupe_key', 'like', 'inbox:%');
}

it('writes a received mail to the timeline of an existing contact', function () {
    $contact = Contact::create(['email' => 'anna.beispiel@example.com']);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $message = Message::sole();
    $entry = inboxTimeline()->sole();

    expect($entry->dedupe_key)->toBe('inbox:'.$message->message_id)
        ->and($entry->contact_id)->toEqual($contact->id);
});

it('writes a sent reply to the timeline of an existing contact', function () {
    Contact::create(['email' => 'anna.beispiel@example.com']);
    fakeSmtp();

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);
    $out = app(ReplySender::class)->send(Conversation::sole(), 'Bis Dienstag!');

    expect(inboxTimeline()->where('dedupe_key', 'inbox:'.$out->message_id)->count())->toBe(1);
});

it('writes nothing and creates no contact for an unknown address', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);

    expect(Message::count())->toBe(1)
        ->and(TimelineEvent::count())->toBe(0)
        ->and(Contact::count())->toBe(0);
});

it('writes nothing for a sent reply to an unknown address', function () {
    fakeSmtp();

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);
    app(ReplySender::class)->send(Conversation::sole(), 'Gern, im Oktober geht es.');

    expect(TimelineEvent::count())->toBe(0)
        ->and(Contact::count())->toBe(0);
});

it('writes each message once, however often its event fires', function () {
    Contact::create(['email' => 'anna.beispiel@example.com']);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $message = Message::sole();

    // A retried job, a second listener run, a re-import.
    event(new InboxMessageReceived($message));
    event(new InboxMessageReceived($message));
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(inboxTimeline()->count())->toBe(1);
});

it('fires InboxMessageReceived for incoming mail, once per new message', function () {
    Event::fake([InboxMessageReceived::class]);

    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    Event::assertDispatchedTimes(InboxMessageReceived::class, 1);
});
