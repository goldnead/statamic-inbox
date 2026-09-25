<?php

/*
 * Spec test 9: every CP route checks its own permission.
 *
 *   view inbox              list, conversation, attachment download
 *   reply inbox             send a reply, change status / snooze / link
 *   manage inbox mailboxes  mailbox list, create, edit, update, connection test
 */

use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->smtp = fakeSmtp();
    $this->mailbox = inboxMailbox();

    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $this->conversation = Conversation::sole();
    $this->message = Message::sole();

    Storage::disk(config('inbox.attachments.disk'))->put('inbox/attachments/1/noten.pdf', '%PDF-1.4 fake');
    $this->attachment = Attachment::create([
        'message_id' => $this->message->id,
        'filename' => 'noten.pdf',
        'mime' => 'application/pdf',
        'size' => 13,
        'path' => 'inbox/attachments/1/noten.pdf',
    ]);
});

/**
 * Each route, the permission it needs, and a request that would succeed with
 * it. The request is a closure over the test case, because the ids it needs
 * only exist once beforeEach() has run.
 */
dataset('inbox routes', [
    'conversation list' => ['view inbox', fn ($t) => ['GET', '/cp/inbox', []]],
    'conversation' => ['view inbox', fn ($t) => ['GET', '/cp/inbox/conversations/'.$t->conversation->id, []]],
    'attachment' => ['view inbox', fn ($t) => ['GET', '/cp/inbox/attachments/'.$t->attachment->id, []]],
    'reply' => ['reply inbox', fn ($t) => ['POST', '/cp/inbox/conversations/'.$t->conversation->id.'/reply', ['text' => 'Hallo Anna']]],
    'status' => ['reply inbox', fn ($t) => ['PATCH', '/cp/inbox/conversations/'.$t->conversation->id, ['status' => 'closed']]],
    'mailbox list' => ['manage inbox mailboxes', fn ($t) => ['GET', '/cp/inbox/mailboxes', []]],
    'mailbox create' => ['manage inbox mailboxes', fn ($t) => ['POST', '/cp/inbox/mailboxes', [
        'name' => 'Chor', 'email' => 'chor@goldner.test',
        'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'username' => 'chor@goldner.test', 'password' => 'another-app-pass',
        'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    ]]],
    'mailbox edit' => ['manage inbox mailboxes', fn ($t) => ['GET', '/cp/inbox/mailboxes/'.$t->mailbox->id.'/edit', []]],
    'mailbox create form' => ['manage inbox mailboxes', fn ($t) => ['GET', '/cp/inbox/mailboxes/create', []]],
    'connection test before saving' => ['manage inbox mailboxes', fn ($t) => ['POST', '/cp/inbox/mailboxes/test', [
        'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'username' => 'chor@goldner.test', 'password' => 'another-app-pass',
        'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    ]]],
    'mailbox update' => ['manage inbox mailboxes', fn ($t) => ['PATCH', '/cp/inbox/mailboxes/'.$t->mailbox->id, [
        'name' => 'Adrian', 'email' => 'adrian@goldner.test',
        'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'username' => 'adrian@goldner.test', 'password' => '',
        'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
    ]]],
    'connection test' => ['manage inbox mailboxes', fn ($t) => ['POST', '/cp/inbox/mailboxes/'.$t->mailbox->id.'/test', []]],
]);

/** Every permission the addon registers except the one given. */
function allInboxPermissionsExcept(string $permission): array
{
    return array_values(array_diff(['view inbox', 'reply inbox', 'manage inbox mailboxes'], [$permission]));
}

it('refuses the route without its permission', function (string $permission, Closure $request) {
    [$method, $uri, $data] = $request($this);

    // Holding every other inbox permission does not help. `reply inbox`
    // routes are tried with `view inbox` in hand, which is the realistic case.
    $user = inboxCpUser(allInboxPermissionsExcept($permission));

    $this->actingAs($user)->json($method, $uri, $data)->assertForbidden();
})->with('inbox routes');

it('allows the route with its permission', function (string $permission, Closure $request) {
    [$method, $uri, $data] = $request($this);

    // `reply inbox` is a child of `view inbox` in the CP, so it never comes alone.
    $permissions = $permission === 'reply inbox' ? ['view inbox', 'reply inbox'] : [$permission];

    $status = $this->actingAs(inboxCpUser($permissions))->json($method, $uri, $data)->baseResponse->getStatusCode();

    expect($status)->toBeGreaterThanOrEqual(200)->toBeLessThan(400);
})->with('inbox routes');

it('sends anonymous visitors to the CP login', function (string $permission, Closure $request) {
    [$method, $uri, $data] = $request($this);

    $this->call($method, $uri, $data)->assertRedirect();
})->with('inbox routes');

it('serves attachments as downloads unless they are an image or a PDF', function () {
    Storage::disk(config('inbox.attachments.disk'))->put('inbox/attachments/1/page.html', '<script>alert(1)</script>');
    $html = Attachment::create([
        'message_id' => $this->message->id,
        'filename' => 'page.html',
        'mime' => 'text/html',
        'size' => 25,
        'path' => 'inbox/attachments/1/page.html',
    ]);

    $user = inboxCpUser(['view inbox']);

    expect($this->actingAs($user)->get('/cp/inbox/attachments/'.$html->id)->headers->get('Content-Disposition'))
        ->toStartWith('attachment');

    expect($this->actingAs($user)->get('/cp/inbox/attachments/'.$this->attachment->id)->headers->get('Content-Disposition'))
        ->toStartWith('inline');
});
