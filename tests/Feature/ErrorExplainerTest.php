<?php

/*
 * Server language in, a cause and a fix out. One mapping for the list, the
 * conversation, the mailbox screens and the reply form.
 */

use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Goldnead\StatamicInbox\Sending\SendFailed;
use Goldnead\StatamicInbox\Support\ErrorExplainer;

beforeEach(function () {
    $this->mailbox = inboxMailbox(['name' => 'Coaching']);
    $this->explainer = app(ErrorExplainer::class);
});

dataset('server messages', [
    'smtp auth' => ['send', '535 5.7.8 Authentication failed: app password rejected', 'auth'],
    'gmail auth' => ['send', 'Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted."', 'auth'],
    'imap auth' => ['fetch', 'IMAP login failed: [AUTHENTICATIONFAILED] Invalid credentials (Failure)', 'auth'],
    'refused' => ['send', 'Connection could not be established with host "smtp.migadu.com:465": stream_socket_client(): Connection refused', 'connection'],
    'timeout' => ['fetch', 'Connection timed out after 30 seconds', 'connection'],
    'dns' => ['test', 'php_network_getaddresses: getaddrinfo for imap.tippfehler.test failed: Name or service not known', 'connection'],
    'recipient' => ['send', 'Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <max@chorwerk.example>: Recipient address rejected: User unknown"', 'recipient'],
    'quota' => ['send', '552 5.2.2 Mailbox full', 'quota'],
    'folder' => ['filed', 'APPEND failed: [TRYCREATE] Mailbox doesn\'t exist: Gesendet', 'folder'],
    'imap folder is not a recipient' => ['fetch', 'SELECT failed: Mailbox not found', 'folder'],
    'tls' => ['test', 'stream_socket_enable_crypto(): SSL operation failed with code 1. error:0A00010B:SSL routines::wrong version number', 'tls'],
    'unknown' => ['send', 'Something odd happened', 'unknown'],
]);

it('names the cause of what the server said', function (string $context, string $raw, string $code) {
    $explained = $this->explainer->explain($raw, $context, $this->mailbox, 'max@chorwerk.example');

    expect($explained['code'])->toBe($code)
        ->and($explained['detail'])->toBe($raw)
        ->and($explained['title'])->not->toContain('535')
        ->and($explained['text'])->not->toBe('');
})->with('server messages');

it('says which mailbox refused the password and links to its password field', function () {
    $this->actingAs(inboxCpUser(['manage inbox mailboxes']));

    $explained = $this->explainer->explain('535 5.7.8 Authentication failed', ErrorExplainer::SEND, $this->mailbox);

    expect($explained['title'])->toBe('The app password for Coaching was refused')
        ->and($explained['action']['label'])->toBe('Renew password')
        ->and($explained['action']['url'])->toEndWith('/cp/inbox/mailboxes/'.$this->mailbox->id.'/edit?tab=account');
});

it('offers no button to someone who may not open the mailbox', function () {
    $this->actingAs(inboxCpUser(['view inbox', 'reply inbox']));

    expect($this->explainer->explain('535 5.7.8 Authentication failed', ErrorExplainer::SEND, $this->mailbox)['action'])->toBeNull();
});

it('explains nothing when nothing went wrong', function () {
    expect($this->explainer->explain(null, ErrorExplainer::SEND))->toBeNull()
        ->and($this->explainer->explain('  ', ErrorExplainer::FETCH))->toBeNull();
});

it('tells a missing AI key from a busy AI', function () {
    expect($this->explainer->explainAi('invalid x-api-key', 401)['code'])->toBe('ai_access')
        ->and($this->explainer->explainAi('No AI key configured (ANTHROPIC_API_KEY).')['code'])->toBe('ai_access')
        ->and($this->explainer->explainAi('Overloaded', 529)['code'])->toBe('ai_busy')
        ->and($this->explainer->explainAi('rate_limit_error', 429)['title'])->toBe('The AI is busy right now')
        ->and($this->explainer->explainAi('The AI could not be reached.')['code'])->toBe('ai_offline')
        // The key is in .env; no Control Panel page can fix it, so no button.
        ->and($this->explainer->explainAi('invalid x-api-key', 401)['action'])->toBeNull();
});

it('puts the explanation on a failed send and on the stored message', function () {
    fakeImap();
    $conversation = Conversation::create([
        'mailbox_id' => $this->mailbox->id, 'subject' => 'Frage', 'counterpart_email' => 'anna@example.com',
    ]);

    app()->instance(ReplySender::class, new class extends ReplySender
    {
        public function __construct() {}

        public function send(Conversation $conversation, string $text, array $attachments = []): Message
        {
            throw new SendFailed('535 5.7.8 Authentication failed');
        }
    });

    $response = $this->actingAs(inboxCpUser(['view inbox', 'reply inbox', 'manage inbox mailboxes']))
        ->postJson('/cp/inbox/conversations/'.$conversation->id.'/reply', ['text' => 'Hallo']);

    $response->assertStatus(502)
        ->assertJsonPath('explanation.code', 'auth')
        ->assertJsonPath('message', 'The app password for Coaching was refused');
});
