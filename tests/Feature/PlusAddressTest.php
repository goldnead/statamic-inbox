<?php

/*
 * 0.2.2, item 1: `lokalteil+beliebig@domain` is one of your own addresses
 * when `lokalteil@domain` is the mailbox's address or an alias. Own
 * addresses are never the other side of a conversation.
 */

use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\SkippedMessage;

beforeEach(function () {
    $this->imap = fakeImap();
});

it('makes no conversation of a mail to your own plus address', function () {
    $mailbox = inboxMailbox();

    deliverAndFetch($this->imap, $mailbox, ['Sent' => ['26-sent-plus-address.eml']]);

    expect(Conversation::count())->toBe(0)
        ->and(SkippedMessage::sole()->reason)->toBe('self');
});

it('makes no conversation of a mail from your own plus address either', function () {
    $mailbox = inboxMailbox();
    $raw = str_replace(
        ['From: Adrian Goldner <adrian@goldner.test>', 'To: adrian+nl-test-1@goldner.test'],
        ['From: Newsletter-Test <adrian+nl-test-1@goldner.test>', 'To: adrian@goldner.test'],
        mailFixture('26-sent-plus-address.eml'),
    );
    $this->imap->client($mailbox)->deliver('INBOX', $raw);

    app(MailboxFetcher::class)->fetch($mailbox->fresh());

    expect(Conversation::count())->toBe(0);
});

it('treats a plus address of an alias as your own', function () {
    $mailbox = inboxMailbox(['email' => 'info@goldner.test', 'username' => 'info@goldner.test', 'aliases' => ['adrian@goldner.test']]);

    deliverAndFetch($this->imap, $mailbox, ['Sent' => ['26-sent-plus-address.eml']]);

    expect(Conversation::count())->toBe(0);
});

it('does not take a plus address of someone else for your own', function () {
    $mailbox = inboxMailbox();
    $raw = str_replace('To: adrian+nl-test-1@goldner.test', 'To: anna+chor@example.com', mailFixture('26-sent-plus-address.eml'));
    $this->imap->client($mailbox)->deliver('Sent', $raw);

    app(MailboxFetcher::class)->fetch($mailbox->fresh());

    expect(Conversation::sole()->counterpart_email)->toBe('anna+chor@example.com');
});

it('knows its own addresses with and without a plus part', function () {
    $mailbox = new Mailbox(['email' => 'Info@Goldner.test', 'aliases' => ['kontakt@goldner.test']]);

    expect($mailbox->isOwnAddress('info@goldner.test'))->toBeTrue()
        ->and($mailbox->isOwnAddress('INFO+nl-test-1@goldner.test'))->toBeTrue()
        ->and($mailbox->isOwnAddress('kontakt+x@goldner.test'))->toBeTrue()
        ->and($mailbox->isOwnAddress('info@other.test'))->toBeFalse()
        ->and($mailbox->isOwnAddress('info+x@other.test'))->toBeFalse()
        ->and($mailbox->isOwnAddress('infox@goldner.test'))->toBeFalse()
        ->and($mailbox->isOwnAddress(''))->toBeFalse();
});

it('knows a plus address of a mailbox address that is a plus address itself', function () {
    $mailbox = new Mailbox(['email' => 'adrian+inbox@goldner.test']);

    expect($mailbox->isOwnAddress('adrian+inbox@goldner.test'))->toBeTrue()
        ->and($mailbox->isOwnAddress('adrian+inbox+nl-1@goldner.test'))->toBeTrue()
        ->and($mailbox->isOwnAddress('adrian+other@goldner.test'))->toBeFalse()
        ->and($mailbox->isOwnAddress('adrian@goldner.test'))->toBeFalse();
});
