<?php

/*
 * IMAP and SMTP hosts are typed in by a CP user and then connected to by the
 * server. Without a guard the mailbox form is a way to probe the network the
 * site runs in. Private, loopback and link-local targets are refused unless
 * `inbox.allow_private_hosts` is on.
 */

use Goldnead\StatamicInbox\Imap\ImapEngineClientFactory;
use Goldnead\StatamicInbox\Mail\SmtpTransportFactory;
use Goldnead\StatamicInbox\Support\HostGuard;
use Goldnead\StatamicInbox\Support\UnsafeHostException;

beforeEach(function () {
    $this->guard = app(HostGuard::class);
    $this->guard->resolveUsing(fn (string $host) => match ($host) {
        'imap.migadu.com' => ['46.23.94.1'],
        'rebind.example' => ['10.0.0.5'],
        'v6-local.example' => ['::1'],
        default => [],
    });
});

afterEach(fn () => app(HostGuard::class)->resolveUsing(null));

it('refuses private, loopback, link-local and metadata addresses', function (string $host) {
    expect(fn () => $this->guard->check($host))->toThrow(UnsafeHostException::class);
})->with([
    'loopback' => '127.0.0.1',
    'private 10/8' => '10.1.2.3',
    'private 192.168/16' => '192.168.178.1',
    'private 172.16/12' => '172.20.0.4',
    'link-local metadata' => '169.254.169.254',
    'IPv6 loopback' => '[::1]',
    'IPv4-mapped IPv6 loopback' => '::ffff:127.0.0.1',
    'name resolving to a private address' => 'rebind.example',
    'name resolving to IPv6 loopback' => 'v6-local.example',
    'name that does not resolve' => 'nowhere.invalid',
]);

it('allows a public host', function () {
    $this->guard->check('imap.migadu.com');
    $this->guard->check('46.23.94.1');

    expect(true)->toBeTrue();
});

it('allows private hosts when the site switches that on', function () {
    config()->set('inbox.allow_private_hosts', true);

    $this->guard->check('192.168.178.1');

    expect(true)->toBeTrue();
});

it('makes the real IMAP adapter refuse a private host before connecting', function () {
    $mailbox = inboxMailbox(['imap_host' => '127.0.0.1']);

    expect(fn () => app(ImapEngineClientFactory::class)->for($mailbox))
        ->toThrow(UnsafeHostException::class);
});

it('makes the real SMTP adapter refuse a private host before connecting', function () {
    $mailbox = inboxMailbox(['smtp_host' => '10.0.0.1']);

    expect(fn () => app(SmtpTransportFactory::class)->for($mailbox))
        ->toThrow(UnsafeHostException::class);
});

it('refuses to save a mailbox with a private host from the CP', function () {
    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->postJson('/cp/inbox/mailboxes', [
            'name' => 'Intern', 'email' => 'intern@goldner.test',
            'imap_host' => '127.0.0.1', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'username' => 'intern@goldner.test', 'password' => 'x',
            'smtp_host' => 'imap.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('imap_host');
});
