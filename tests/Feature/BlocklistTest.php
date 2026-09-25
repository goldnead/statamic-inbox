<?php

/*
 * Filter spec, test 7 and the "Neu" actions: hiding a sender or a domain
 * puts a rule on the mailbox's blocklist, deletes that sender's conversations
 * (attachment files included) and keeps future mail out. Removing the rule
 * lets future mail through again. Gmail is never touched.
 */

use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\BlockRule;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
    $this->user = inboxCpUser(['view inbox', 'reply inbox']);
});

it('hides a sender: deletes the conversation and its attachment files, and keeps future mail out', function () {
    $client = deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['09-inline-image.eml', '08-no-message-id.eml']]);

    $anna = Message::where('from_email', 'anna.beispiel@example.com')->sole()->conversation;
    $paths = Attachment::pluck('path')->all();
    $disk = Storage::disk(config('inbox.attachments.disk'));

    expect($paths)->not->toBeEmpty();
    foreach ($paths as $path) {
        expect($disk->exists($path))->toBeTrue();
    }

    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.$anna->id.'/block', ['scope' => 'sender'])
        ->assertSuccessful();

    expect(Conversation::find($anna->id))->toBeNull()
        ->and(Message::where('from_email', 'anna.beispiel@example.com')->count())->toBe(0)
        ->and(Attachment::count())->toBe(0)
        ->and(Conversation::count())->toBe(1);
    foreach ($paths as $path) {
        expect($disk->exists($path))->toBeFalse();
    }

    $rule = BlockRule::sole();
    expect($rule->mailbox_id)->toBe($this->mailbox->id)
        ->and($rule->type)->toBe('sender')
        ->and($rule->value)->toBe('anna.beispiel@example.com');

    // A new mail from Anna stays out.
    $client->deliver('INBOX', mailFixture('01-new-thread.eml'));
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Message::where('from_email', 'anna.beispiel@example.com')->count())->toBe(0);
});

it('hides a whole domain', function () {
    $raw = str_replace(['bob@example.org', 'bob-20260923-1100'], ['alice@example.org', 'alice-1'], mailFixture('06-same-subject-other-sender.eml'));
    $client = deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);
    $client->deliver('INBOX', $raw);
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Conversation::count())->toBe(2);

    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.Conversation::first()->id.'/block', ['scope' => 'domain'])
        ->assertSuccessful();

    expect(Conversation::count())->toBe(0)
        ->and(BlockRule::sole()->only(['type', 'value']))->toBe(['type' => 'domain', 'value' => 'example.org']);
});

it('refuses to hide the mailbox\'s own domain', function () {
    $raw = str_replace('bob@example.org', 'kollege@goldner.test', mailFixture('06-same-subject-other-sender.eml'));
    $this->imap->client($this->mailbox)->deliver('INBOX', $raw);
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.Conversation::sole()->id.'/block', ['scope' => 'domain'])
        ->assertStatus(422);

    expect(BlockRule::count())->toBe(0)->and(Conversation::count())->toBe(1);
});

it('refuses to hide a whole freemail domain, where every sender is someone else', function () {
    $raw = str_replace('bob@example.org', 'bob.tenor@gmail.com', mailFixture('06-same-subject-other-sender.eml'));
    $this->imap->client($this->mailbox)->deliver('INBOX', $raw);
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.Conversation::sole()->id.'/block', ['scope' => 'domain'])
        ->assertStatus(422);

    $row = $this->actingAs($this->user)->getJson('/cp/inbox?tab=new')->json('data.0');

    expect(BlockRule::count())->toBe(0)
        ->and(Conversation::count())->toBe(1)
        ->and($row['can_hide_domain'])->toBeFalse();
});

it('lets future mail through again once the rule is removed', function () {
    $client = deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);

    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.Conversation::sole()->id.'/block', ['scope' => 'sender'])
        ->assertSuccessful();

    $blocked = str_replace('bob-20260923-1100', 'bob-2', mailFixture('06-same-subject-other-sender.eml'));
    $client->deliver('INBOX', $blocked);
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());
    expect(Conversation::count())->toBe(0);

    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->deleteJson('/cp/inbox/mailboxes/'.$this->mailbox->id.'/rules/'.BlockRule::sole()->id)
        ->assertSuccessful();

    $client->deliver('INBOX', str_replace('bob-20260923-1100', 'bob-3', mailFixture('06-same-subject-other-sender.eml')));
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(BlockRule::count())->toBe(0)
        ->and(Message::pluck('message_id')->all())->toBe(['bob-3@example.org']);
});

it('keeps the hide actions from someone who may only read', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml']]);

    $this->actingAs(inboxCpUser(['view inbox']))
        ->postJson('/cp/inbox/conversations/'.Conversation::sole()->id.'/block', ['scope' => 'sender'])
        ->assertForbidden();

    expect(Conversation::count())->toBe(1);
});

it('accepts a new conversation without a contact, and it stays accepted', function () {
    $client = deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);
    $conversation = Conversation::sole();

    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.$conversation->id.'/accept')
        ->assertSuccessful();

    expect($conversation->fresh()->status)->toBe('open');

    // Carla writes again: still relevant, not back to new.
    $again = str_replace(['Subject: Termin?', '16:45:00'], ['Subject: Re: Termin?', '18:00:00'], mailFixture('08-no-message-id.eml'));
    $client->deliver('INBOX', $again);
    app(MailboxFetcher::class)->fetch($this->mailbox->fresh());

    expect(Conversation::sole()->status)->toBe('open');
});

it('makes a conversation relevant when a contact is created from it', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);

    $this->actingAs($this->user)
        ->postJson('/cp/inbox/conversations/'.Conversation::sole()->id.'/contact')
        ->assertSuccessful();

    expect(Conversation::sole()->status)->toBe('open');
});
