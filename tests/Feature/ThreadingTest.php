<?php

/*
 * Spec test 2: the three threading rules in order (In-Reply-To, References,
 * counterpart plus normalised subject within 30 days), and the fall-through
 * to a new conversation.
 */

use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();

    // Anna's first mail and Adrian's answer from his mail client.
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    $this->thread = Conversation::sole();
});

function conversationOf(string $messageId): Conversation
{
    return Message::where('message_id', $messageId)->sole()->conversation;
}

it('threads by In-Reply-To first', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['03-gmail-reply.eml']]);

    expect(conversationOf('CAanna003+Zr9Lm@mail.gmail.com')->id)->toBe($this->thread->id)
        ->and(conversationOf('adrian-out-001@goldner.test')->id)->toBe($this->thread->id);
});

it('threads by References when In-Reply-To is missing, even if only a later entry matches', function () {
    // Different subject, no In-Reply-To; the first reference is unknown, the
    // second is Adrian's answer.
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['04-references-only.eml']]);

    expect(conversationOf('20260922173000.GA4711@mobile.example.com')->id)->toBe($this->thread->id)
        ->and(Conversation::count())->toBe(1);
});

it('threads by counterpart and normalised subject within 30 days when no header matches', function () {
    // "AW: Frage zum Coaching", no In-Reply-To, no References.
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['05-subject-match.eml']]);

    expect(conversationOf('1a2b3c4d-5e6f-4a1b-9c8d-7e6f5a4b3c2d@outlook.example.com')->id)
        ->toBe($this->thread->id);
});

it('does not thread by subject when the counterpart differs', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);

    $bob = conversationOf('bob-20260923-1100@example.org');

    expect($bob->id)->not->toBe($this->thread->id)
        ->and($bob->counterpart_email)->toBe('bob@example.org')
        ->and(Conversation::count())->toBe(2);
});

it('does not thread by subject when the last message is older than 30 days', function () {
    $this->thread->update(['last_message_at' => Carbon::parse('2026-08-10 10:00:00')]);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['05-subject-match.eml']]);

    expect(conversationOf('1a2b3c4d-5e6f-4a1b-9c8d-7e6f5a4b3c2d@outlook.example.com')->id)
        ->not->toBe($this->thread->id);
});

it('prefers a header match over a subject match', function () {
    // A second conversation with Anna under the same subject, more recent
    // than the real thread. The References header must still win.
    $decoy = Conversation::create([
        'mailbox_id' => $this->mailbox->id,
        'subject' => 'Noch eine Kleinigkeit',
        'counterpart_email' => 'anna.beispiel@example.com',
        'status' => 'open',
        'unread' => false,
        'last_message_at' => Carbon::parse('2026-09-22 12:00:00'),
    ]);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['04-references-only.eml']]);

    expect(conversationOf('20260922173000.GA4711@mobile.example.com')->id)
        ->toBe($this->thread->id)
        ->not->toBe($decoy->id);
});

it('opens a new conversation for an unrelated message', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);

    $carla = Message::where('from_email', 'carla@example.net')->sole()->conversation;

    expect($carla->id)->not->toBe($this->thread->id)
        ->and($carla->subject)->toBe('Termin?')
        // Unknown sender: a first contact, "new" since the filter (0.2).
        ->and($carla->status)->toBe('new')
        ->and(Conversation::count())->toBe(2);
});

it('keeps conversations of different mailboxes apart even for the same counterpart and subject', function () {
    $other = inboxMailbox(['email' => 'chor@goldner.test', 'username' => 'chor@goldner.test']);

    deliverAndFetch($this->imap, $other, ['INBOX' => ['05-subject-match.eml']]);

    $conversation = conversationOf('1a2b3c4d-5e6f-4a1b-9c8d-7e6f5a4b3c2d@outlook.example.com');

    expect($conversation->id)->not->toBe($this->thread->id)
        ->and($conversation->mailbox_id)->toBe($other->id);
});
