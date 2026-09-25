<?php

/*
 * The Control Panel pages: which Inertia component each route renders, what
 * the page is handed, and the JSON core's Listing asks the inbox URL for.
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\FetchFailure;
use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->smtp = fakeSmtp();
    $this->mailbox = inboxMailbox();
});

function inertiaPage($response): array
{
    $response->assertOk();

    return $response->viewData('page');
}

it('renders the inbox page with columns, the mailbox filter and what the fetch could not do', function () {
    inboxMailbox(['name' => 'Chor', 'email' => 'chor@goldner.test', 'last_error' => 'IMAP login failed: [AUTHENTICATIONFAILED] Invalid credentials', 'last_error_scope' => 'mailbox']);
    FetchFailure::create([
        'mailbox_id' => $this->mailbox->id, 'folder' => 'INBOX', 'uid' => 7, 'error' => 'broken',
        'attempts' => 3, 'gave_up_at' => Carbon::now(),
    ]);
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);
    acceptAllConversations();

    $page = inertiaPage($this->actingAs(inboxCpUser(['view inbox']))->get('/cp/inbox'));

    expect($page['component'])->toBe('inbox::Conversations/Index')
        ->and(collect($page['props']['columns'])->pluck('field')->all())->toBe(['counterpart', 'subject', 'mailbox', 'last_message_at'])
        ->and(collect($page['props']['filters'])->pluck('handle')->all())->toContain('inbox_mailbox')
        ->and($page['props']['tabCounts'])->toBe(['open' => 1, 'waiting' => 0, 'closed' => 0, 'snoozed' => 0, 'new' => 0])
        ->and(collect($page['props']['mailboxes'])->firstWhere('name', 'Chor')['last_error_scope'])->toBe('mailbox')
        ->and($page['props']['failures'])->toBe([['mailbox_id' => $this->mailbox->id, 'folder' => 'INBOX', 'count' => 1, 'latest' => FetchFailure::sole()->id]])
        ->and(collect($page['props']['mailboxes'])->firstWhere('name', 'Chor')['problem']['title'])->toBe('The app password for Chor was refused')
        ->and($page['props']['canReply'])->toBeFalse()
        ->and($page['props']['canManageMailboxes'])->toBeFalse();
});

it('never hands the mailbox password to the inbox page', function () {
    $html = $this->actingAs(inboxCpUser(['view inbox']))->get('/cp/inbox')->getContent();

    expect($html)->not->toContain(INBOX_TEST_PASSWORD);
});

it('answers the listing with rows and meta.columns, per tab', function () {
    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '06-same-subject-other-sender.eml'],
    ]);
    acceptAllConversations();
    Conversation::query()->where('counterpart_email', 'anna.beispiel@example.com')->update(['status' => 'waiting']);

    $user = inboxCpUser(['view inbox']);
    $open = $this->actingAs($user)->getJson('/cp/inbox?tab=open')->assertOk();
    $waiting = $this->actingAs($user)->getJson('/cp/inbox?tab=waiting')->assertOk();

    expect($open->json('meta.columns'))->toHaveCount(4)
        ->and($open->json('meta.total'))->toBe(1)
        ->and($waiting->json('data.0.counterpart_email'))->toBe('anna.beispiel@example.com')
        ->and($waiting->json('data.0.counterpart'))->toBe('Anna Beispiel')
        ->and($waiting->json('data.0.show_url'))->toEndWith('/cp/inbox/conversations/'.$waiting->json('data.0.id'));
});

it('lists snoozed conversations only under Geschlummert', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);
    acceptAllConversations();
    Conversation::query()->update(['snoozed_until' => Carbon::now()->addDay()]);

    $user = inboxCpUser(['view inbox']);

    expect($this->actingAs($user)->getJson('/cp/inbox?tab=open')->json('meta.total'))->toBe(0)
        ->and($this->actingAs($user)->getJson('/cp/inbox?tab=snoozed')->json('meta.total'))->toBe(1);
});

it('searches by name and filters by mailbox through the core filter', function () {
    // The newsletter is bulk mail; this mailbox keeps it, the filter is not the point here.
    $chor = inboxMailbox(['name' => 'Chor', 'email' => 'chor@goldner.test', 'skip_bulk' => false]);
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);
    deliverAndFetch($this->imap, $chor, ['INBOX' => ['07-html-tracking.eml']]);
    acceptAllConversations();

    $user = inboxCpUser(['view inbox']);
    $filters = base64_encode(json_encode(['inbox_mailbox' => ['mailbox' => (string) $chor->id]]));

    $byName = $this->actingAs($user)->getJson('/cp/inbox?search=Beispiel')->json('data');
    $filtered = $this->actingAs($user)->getJson('/cp/inbox?filters='.$filters);

    expect(collect($byName)->pluck('counterpart_email')->all())->toBe(['anna.beispiel@example.com'])
        ->and(collect($filtered->json('data'))->pluck('mailbox')->all())->toBe(['Chor'])
        ->and($filtered->json('meta.activeFilterBadges.inbox_mailbox'))->toContain('Chor');
});

it('shows the LeadHub name, an HTML-only excerpt and a failed send in the row', function () {
    Contact::create(['email' => 'anna.beispiel@example.com', 'first_name' => 'Annegret', 'last_name' => 'Beispiel']);
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml', '09-inline-image.eml']]);

    $conversation = Conversation::query()->where('subject', 'Frage zum Coaching')->sole();
    Message::create([
        'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'direction' => Message::OUT,
        'message_id' => 'inbox.failed@goldner.test', 'from_email' => 'adrian@goldner.test', 'subject' => 'Re: Frage zum Coaching',
        'text' => 'Hallo', 'sent_at' => Carbon::parse('2026-09-19 10:00'), 'send_error' => 'SMTP refused',
    ]);

    $rows = collect($this->actingAs(inboxCpUser(['view inbox']))->getJson('/cp/inbox')->json('data'))->keyBy('subject');

    expect($rows['Frage zum Coaching']['counterpart'])->toBe('Annegret Beispiel')
        ->and($rows['Frage zum Coaching']['has_send_error'])->toBeTrue()
        ->and($rows['Foto vom Konzert']['excerpt'])->toBe('Hier das Foto:')
        ->and($rows['Foto vom Konzert']['has_send_error'])->toBeFalse();
});

it('renders a conversation with its URLs, the contact link and the reply permission', function () {
    // LeadHub's CP routes are not mounted in this bed; its contact route is
    // all the page needs from it.
    Route::get('cp/leadhub/contacts/{contact}', fn () => '')->name('statamic.cp.leadhub.contacts.show');
    app('router')->getRoutes()->refreshNameLookups();

    $contact = Contact::create(['email' => 'anna.beispiel@example.com', 'first_name' => 'Anna']);
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);
    $conversation = Conversation::sole();

    $page = inertiaPage($this->actingAs(inboxCpUser(['view inbox', 'reply inbox']))->get('/cp/inbox/conversations/'.$conversation->id));

    expect($page['component'])->toBe('inbox::Conversations/Show')
        ->and($page['props']['canReply'])->toBeTrue()
        ->and($page['props']['templates'])->toBe([])
        ->and($page['props']['contact']['id'])->toBe($contact->id)
        ->and($page['props']['contact']['url'])->toEndWith('/cp/leadhub/contacts/'.$contact->id)
        ->and(array_keys($page['props']['urls']))->toBe(['index', 'update', 'reply', 'draft', 'template', 'contact'])
        ->and($page['props']['messages'])->toHaveCount(1);
});

it('tells a read-only user the conversation cannot be answered', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $page = inertiaPage($this->actingAs(inboxCpUser(['view inbox']))->get('/cp/inbox/conversations/'.Conversation::sole()->id));

    expect($page['props']['canReply'])->toBeFalse();
});

it('renders the mailbox list, the create form and the edit form without the password', function () {
    $user = inboxCpUser(['manage inbox mailboxes']);

    $index = inertiaPage($this->actingAs($user)->get('/cp/inbox/mailboxes'));
    $create = inertiaPage($this->actingAs($user)->get('/cp/inbox/mailboxes/create'));
    $edit = $this->actingAs($user)->get('/cp/inbox/mailboxes/'.$this->mailbox->id.'/edit');

    expect($index['component'])->toBe('inbox::Mailboxes/Index')
        ->and($index['props']['mailboxes'][0]['edit_url'])->toEndWith('/cp/inbox/mailboxes/'.$this->mailbox->id.'/edit')
        ->and($index['props']['createUrl'])->toEndWith('/cp/inbox/mailboxes/create')
        ->and($create['component'])->toBe('inbox::Mailboxes/Edit')
        ->and($create['props']['isNew'])->toBeTrue()
        ->and($create['props']['mailbox']['has_password'])->toBeFalse()
        ->and($create['props']['testUrl'])->toEndWith('/cp/inbox/mailboxes/test')
        ->and($create['props']['presets'])->toHaveKey('google')
        ->and(inertiaPage($edit)['props']['mailbox']['has_password'])->toBeTrue()
        ->and(inertiaPage($edit)['props']['isNew'])->toBeFalse()
        ->and($edit->getContent())->not->toContain(INBOX_TEST_PASSWORD);
});

it('tests an unsaved mailbox with the values in the form', function () {
    $response = $this->actingAs(inboxCpUser(['manage inbox mailboxes']))->postJson('/cp/inbox/mailboxes/test', [
        'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'username' => 'chor@goldner.test', 'password' => 'another-app-pass',
        'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    ]);

    $response->assertOk()->assertJson(['imap' => ['ok' => true], 'smtp' => ['ok' => true]]);
});

it('needs the password to test an unsaved mailbox', function () {
    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))->postJson('/cp/inbox/mailboxes/test', [
        'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'username' => 'chor@goldner.test',
        'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});
