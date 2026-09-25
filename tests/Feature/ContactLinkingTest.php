<?php

/*
 * Spec test 3: a known address is linked to its LeadHub contact; an unknown
 * one creates no contact. LeadHub::ingest would create one, so the addon has
 * to ask LeadHub::findByEmail first.
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
});

it('links a conversation to the LeadHub contact with that address', function () {
    // Stored in a different case than the From header carries it.
    $contact = Contact::create(['email' => 'anna.beispiel@example.com', 'first_name' => 'Anna']);

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    expect(Conversation::sole()->contact_id)->toEqual($contact->id);
});

it('links an outgoing-only conversation through the recipient, not the mailbox', function () {
    $contact = Contact::create(['email' => 'anna.beispiel@example.com']);
    Contact::create(['email' => 'adrian@goldner.test']);

    deliverAndFetch($this->imap, $this->mailbox, ['Sent' => ['02-sent-reply.eml']]);

    expect(Conversation::sole()->contact_id)->toEqual($contact->id);
});

it('never creates a contact for an unknown address', function () {
    Contact::create(['email' => 'someone-else@example.com']);

    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['07-html-tracking.eml', '08-no-message-id.eml'],
    ]);

    expect(Message::count())->toBe(2)
        ->and(Conversation::whereNotNull('contact_id')->count())->toBe(0)
        ->and(Contact::count())->toBe(1)
        ->and(Contact::where('email', 'carla@example.net')->exists())->toBeFalse()
        ->and(Contact::where('email', 'newsletter@shop.example')->exists())->toBeFalse();
});
