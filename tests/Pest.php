<?php

use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Contracts\TransportFactory;
use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Tests\Fakes\ArrayTransportFactory;
use Goldnead\StatamicInbox\Tests\Fakes\FakeMailboxClient;
use Goldnead\StatamicInbox\Tests\Fakes\FakeMailboxClientFactory;
use Goldnead\StatamicInbox\Tests\TestCase;
use Illuminate\Support\Str;
use Statamic\Facades\Role;
use Statamic\Facades\User;

uses(TestCase::class)->in('Feature', 'Unit');

/** The app password every test mailbox is created with. */
const INBOX_TEST_PASSWORD = 's3cr3t-app-pass-7Kq';

/** Raw RFC822 of a fixture under tests/Fixtures/mail. */
function mailFixture(string $name): string
{
    return file_get_contents(__DIR__.'/Fixtures/mail/'.$name)
        ?: throw new RuntimeException("Missing mail fixture {$name}.");
}

/**
 * A mail the website sent from the mailbox's own address (a confirmation,
 * an invoice), as it lies in Sent.
 *
 * @param  array<string, string>  $headers
 */
function systemMail(string $messageId, string $subject, array $headers = [], string $to = 'Tina Testkauf <tina.testkauf@example.com>', string $date = 'Wed, 23 Sep 2026 10:00:00 +0200'): string
{
    $extra = collect($headers)->map(fn ($value, $name) => "{$name}: {$value}")->implode("\n");

    return trim(implode("\n", array_filter([
        'MIME-Version: 1.0',
        "Date: {$date}",
        "Message-ID: <{$messageId}>",
        "Subject: {$subject}",
        'From: Adrian Goldner <adrian@goldner.test>',
        "To: {$to}",
        $extra,
        'Content-Type: text/plain; charset="UTF-8"',
    ])))."\n\nAutomatisch versendet.\n";
}

/**
 * A mailbox with the settings a Migadu account would have. Deliberately leaves
 * `append_sent` and `import_since` unset, so every test meets the defaults.
 *
 * @param  array<string, mixed>  $attributes
 */
function inboxMailbox(array $attributes = []): Mailbox
{
    return Mailbox::create([
        'name' => 'Adrian',
        'email' => 'adrian@goldner.test',
        'imap_host' => 'imap.migadu.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'username' => 'adrian@goldner.test',
        'password' => INBOX_TEST_PASSWORD,
        'smtp_host' => 'smtp.migadu.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'inbox_folder' => 'INBOX',
        'sent_folder' => 'Sent',
        'active' => true,
        ...$attributes,
    ]);
}

/** A Google Workspace mailbox: Gmail files SMTP mail into Sent by itself. */
function gmailMailbox(array $attributes = []): Mailbox
{
    return inboxMailbox([
        'imap_host' => 'imap.gmail.com',
        'smtp_host' => 'smtp.gmail.com',
        'sent_folder' => '[Gmail]/Gesendet',
        ...$attributes,
    ]);
}

/** Swap the IMAP seam for the in-memory server and return it. */
function fakeImap(): FakeMailboxClientFactory
{
    $factory = new FakeMailboxClientFactory;
    app()->instance(MailboxClientFactory::class, $factory);

    return $factory;
}

/** Swap the SMTP seam for an in-memory transport and return it. */
function fakeSmtp(): ArrayTransportFactory
{
    $factory = new ArrayTransportFactory;
    app()->instance(TransportFactory::class, $factory);

    return $factory;
}

/**
 * Deliver fixtures into a mailbox's fake server and fetch once.
 *
 * @param  array<string, list<string>>  $folders  folder => fixture names, in delivery order
 */
function deliverAndFetch(FakeMailboxClientFactory $imap, Mailbox $mailbox, array $folders): FakeMailboxClient
{
    $client = $imap->client($mailbox);

    foreach ($folders as $folder => $fixtures) {
        foreach ($fixtures as $fixture) {
            $client->deliver($folder, mailFixture($fixture));
        }
    }

    app(MailboxFetcher::class)->fetch($mailbox->fresh());

    return $client;
}

/**
 * Take every first contact out of "Neu", as "Übernehmen" would. For tests
 * about the listing and the pages, where the fixtures' unknown senders would
 * otherwise all sit in the "Neu" tab since the filter (0.2).
 */
function acceptAllConversations(): void
{
    Conversation::query()
        ->where('status', 'new')
        ->update(['status' => 'open', 'accepted_at' => now()]);
}

/**
 * A CP user holding exactly these permissions (plus `access cp`).
 *
 * @param  list<string>  $permissions
 */
function inboxCpUser(array $permissions): Statamic\Contracts\Auth\User
{
    $handle = 'role-'.Str::random(8);
    Role::make($handle)->addPermission(['access cp', ...$permissions])->save();

    $user = User::make()->email(Str::random(8).'@example.test')->assignRole($handle);
    $user->save();

    return $user;
}
