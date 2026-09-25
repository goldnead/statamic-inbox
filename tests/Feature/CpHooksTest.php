<?php

/*
 * The backend the CP screens will stand on (review of b441eb7, items 10 to
 * 12): contact linking only to contacts that exist, a list without one query
 * per row, the nav item with the unread count, the "E-Mails" panel on the
 * LeadHub contact, and "Kontakt anlegen" from a conversation.
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\ContactPanels;
use Goldnead\StatamicInbox\Models\Conversation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
});

it('refuses to link a conversation to a contact that does not exist', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);
    $conversation = Conversation::sole();

    $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))
        ->patchJson('/cp/inbox/conversations/'.$conversation->id, ['contact_id' => 99999])
        ->assertJsonValidationErrors('contact_id');

    expect($conversation->fresh()->contact_id)->toBeNull();
});

it('links a conversation to a contact that exists, and unlinks with null', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);
    $conversation = Conversation::sole();
    $contact = Contact::create(['email' => 'carla.privat@example.net']);
    $user = inboxCpUser(['view inbox', 'reply inbox']);

    $this->actingAs($user)->patchJson('/cp/inbox/conversations/'.$conversation->id, ['contact_id' => $contact->id])
        ->assertSuccessful();
    expect($conversation->fresh()->contact_id)->toBe($contact->id);

    $this->actingAs($user)->patchJson('/cp/inbox/conversations/'.$conversation->id, ['contact_id' => null])
        ->assertSuccessful();
    expect($conversation->fresh()->contact_id)->toBeNull();
});

it('lists conversations with a constant number of queries', function () {
    $user = inboxCpUser(['view inbox']);
    $count = function () use ($user) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        // The rows come from the JSON the Listing asks the same URL for.
        $this->actingAs($user)->getJson('/cp/inbox')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);
    $few = $count();

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['06-same-subject-other-sender.eml', '07-html-tracking.eml', '08-no-message-id.eml']]);
    $many = $count();

    expect(Conversation::count())->toBe(4)
        ->and($many)->toBe($few);
});

it('shows the excerpt of the newest message in the list', function () {
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '03-gmail-reply.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    $rows = $this->actingAs(inboxCpUser(['view inbox']))->getJson('/cp/inbox')->json('data');

    expect($rows[0]['excerpt'])->toStartWith('Dienstag passt super');
});

it('adds a Postfach nav item with the unread count for view inbox', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml', '06-same-subject-other-sender.eml']]);

    expect($this->navCallbacks)->not->toBeEmpty();

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

    foreach ($this->navCallbacks as $callback) {
        $callback($nav);
    }

    $item = collect($nav->items)->first(fn ($item) => str_starts_with($item->name, 'Postfach'));

    expect($item)->not->toBeNull()
        ->and($item->name)->toBe('Postfach (2)')
        ->and($item->calls['route'])->toBe('inbox.index')
        ->and($item->calls['can'])->toBe('view inbox')
        ->and($item->calls['attributes'])->toBe(['data-inbox-unread' => 2]);
});

it('shows the latest conversations as a panel on the LeadHub contact', function () {
    $contact = Contact::create(['email' => 'anna.beispiel@example.com']);
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $panel = collect(app(ContactPanels::class)->forContact($contact))->firstWhere('key', 'inbox');

    expect($panel)->not->toBeNull()
        ->and($panel['rows'][0]['label'])->toBe('Frage zum Coaching')
        ->and($panel['rows'][0]['url'])->toContain('/cp/inbox/conversations/'.Conversation::sole()->id);
});

it('creates the LeadHub contact from a conversation on request, and links it', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);
    $conversation = Conversation::sole();

    $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))
        ->postJson('/cp/inbox/conversations/'.$conversation->id.'/contact')
        ->assertSuccessful();

    $contact = Contact::where('email', 'carla@example.net')->sole();

    expect($conversation->fresh()->contact_id)->toBe($contact->id)
        ->and($contact->first_name)->toBe('Carla')
        ->and($contact->last_name)->toBe('Alt');
});

it('refuses "Kontakt anlegen" to someone who may only read', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['08-no-message-id.eml']]);

    $this->actingAs(inboxCpUser(['view inbox']))
        ->postJson('/cp/inbox/conversations/'.Conversation::sole()->id.'/contact')
        ->assertForbidden();

    expect(Contact::count())->toBe(0);
});
