<?php

/*
 * 0.2.2, item 5 (seen on the ChoirLive staging site): a reply sent from the
 * inbox is stored before it has a UID. When the fetch meets it in Sent, the
 * UID is filled in, so `inbox:reclassify` can read its headers later.
 */

use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Sending\ReplySender;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->smtp = fakeSmtp();
});

it('fills in the UID of a reply when the fetch meets its APPENDed copy in Sent', function () {
    $mailbox = inboxMailbox();
    deliverAndFetch($this->imap, $mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $out = app(ReplySender::class)->send(Conversation::sole(), 'Bis Dienstag!');
    expect($out->fresh()->imap_uid)->toBeNull();

    app(MailboxFetcher::class)->fetch($mailbox->fresh());

    $client = $this->imap->client($mailbox);
    $uid = array_key_last($client->folders['Sent']);

    expect($out->fresh()->imap_uid)->toBe($uid)
        ->and($out->fresh()->folder)->toBe('Sent')
        ->and(Message::where('message_id', $out->message_id)->count())->toBe(1);
});

it('fills in the UID when Gmail files the reply into its own Sent folder', function () {
    $mailbox = gmailMailbox();
    $client = deliverAndFetch($this->imap, $mailbox, ['INBOX' => ['01-new-thread.eml']]);

    $out = app(ReplySender::class)->send(Conversation::sole(), 'Bis Dienstag!');
    $uid = $client->deliver('[Gmail]/Gesendet', $this->smtp->transport->messages()->last()->toString());

    app(MailboxFetcher::class)->fetch($mailbox->fresh());

    expect($out->fresh()->imap_uid)->toBe($uid)
        ->and($out->fresh()->folder)->toBe('[Gmail]/Gesendet');
});

it('leaves a UID that is already stored alone', function () {
    $mailbox = inboxMailbox();
    $client = deliverAndFetch($this->imap, $mailbox, ['Sent' => ['02-sent-reply.eml']]);
    $message = Message::sole();
    $uid = $message->imap_uid;

    // The same mail once more under a new UID (moved back, copied).
    $client->deliver('Sent', mailFixture('02-sent-reply.eml'));
    app(MailboxFetcher::class)->fetch($mailbox->fresh());

    expect($message->fresh()->imap_uid)->toBe($uid);
});
