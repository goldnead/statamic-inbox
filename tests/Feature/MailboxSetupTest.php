<?php

/*
 * Creating a mailbox: Gmail is recognised (no APPEND, Gmail files SMTP mail
 * into Sent itself), the Sent folder is detected on the server when the form
 * leaves it empty, and a connection test reports IMAP and SMTP separately.
 */

use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Support\HostGuard;
use Goldnead\StatamicInbox\Tests\Fakes\FakeMailboxClient;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->smtp = fakeSmtp();
    app(HostGuard::class)->resolveUsing(fn () => ['46.23.94.1']);
});

afterEach(fn () => app(HostGuard::class)->resolveUsing(null));

it('recognises Gmail and Google Workspace hosts', function (string $host, bool $gmail) {
    expect(Mailbox::isGmailHost($host))->toBe($gmail);
})->with([
    ['imap.gmail.com', true],
    ['IMAP.GMAIL.COM', true],
    ['imap.googlemail.com', true],
    ['smtp.gmail.com', true],
    ['imap.migadu.com', false],
    ['gmail.com.evil.example', false],
    ['imap.notgmail.com', false],
]);

it('defaults append_sent to false for a googlemail host and true elsewhere', function () {
    expect(inboxMailbox(['imap_host' => 'imap.googlemail.com'])->append_sent)->toBeFalse()
        ->and(inboxMailbox(['email' => 'b@goldner.test'])->append_sent)->toBeTrue();
});

function mailboxForm(array $overrides = []): array
{
    return [
        'name' => 'Chor', 'email' => 'chor@goldner.test',
        'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'username' => 'chor@goldner.test', 'password' => 'another-app-pass',
        'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
        ...$overrides,
    ];
}

it('detects the Sent folder on the server when the form leaves it empty', function () {
    // The fake hands out one client per mailbox id; the next id is 1.
    $this->imap->clients[1] = (new FakeMailboxClient);
    $this->imap->clients[1]->sentFolder = 'Gesendete Objekte';

    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->postJson('/cp/inbox/mailboxes', mailboxForm())
        ->assertSuccessful();

    expect(Mailbox::sole()->sent_folder)->toBe('Gesendete Objekte')
        ->and(Mailbox::sole()->password)->toBe('another-app-pass');
});

it('keeps a Sent folder the form names', function () {
    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->postJson('/cp/inbox/mailboxes', mailboxForm(['sent_folder' => 'INBOX.Sent']))
        ->assertSuccessful();

    expect(Mailbox::sole()->sent_folder)->toBe('INBOX.Sent');
});

it('requires a password for a new mailbox', function () {
    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->postJson('/cp/inbox/mailboxes', mailboxForm(['password' => '']))
        ->assertJsonValidationErrors('password');
});

it('reports a working connection test for IMAP and SMTP', function () {
    $mailbox = inboxMailbox();

    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->postJson('/cp/inbox/mailboxes/'.$mailbox->id.'/test')
        ->assertOk()
        ->assertJson(['imap' => ['ok' => true], 'smtp' => ['ok' => true]]);
});
