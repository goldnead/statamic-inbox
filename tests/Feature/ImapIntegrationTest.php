<?php

/*
 * The real adapters against a real server. Opt-in: runs only when the
 * connection is given in the environment, and never in CI.
 *
 *   INBOX_IT_IMAP_HOST=imap.migadu.com INBOX_IT_USERNAME=… INBOX_IT_PASSWORD=… \
 *   [INBOX_IT_IMAP_PORT=993] [INBOX_IT_ENCRYPTION=ssl] vendor/bin/pest --filter=ImapIntegration
 *
 * It only reads: login, folder list, the newest UIDs of INBOX, one raw
 * message. It never appends and never sends.
 */

use Goldnead\StatamicInbox\Imap\ImapEngineClientFactory;
use Illuminate\Support\Carbon;

beforeEach(function () {
    if (! env('INBOX_IT_IMAP_HOST') || ! env('INBOX_IT_USERNAME') || ! env('INBOX_IT_PASSWORD')) {
        $this->markTestSkipped('Set INBOX_IT_IMAP_HOST, INBOX_IT_USERNAME and INBOX_IT_PASSWORD to run against a real server.');
    }
});

it('logs in, finds Sent and reads the newest INBOX message', function () {
    $mailbox = inboxMailbox([
        'email' => env('INBOX_IT_USERNAME'),
        'username' => env('INBOX_IT_USERNAME'),
        'password' => env('INBOX_IT_PASSWORD'),
        'imap_host' => env('INBOX_IT_IMAP_HOST'),
        'imap_port' => (int) env('INBOX_IT_IMAP_PORT', 993),
        'imap_encryption' => env('INBOX_IT_ENCRYPTION', 'ssl'),
    ]);

    $client = app(ImapEngineClientFactory::class)->for($mailbox);

    $client->check();
    expect($client->detectSentFolder())->toBeString();

    $uids = $client->uidsAfter('INBOX', 0, Carbon::now()->subDays(7));

    if ($uids === []) {
        $this->markTestIncomplete('INBOX has no message from the last 7 days to read.');
    }

    expect($client->fetchRaw('INBOX', end($uids)))->toContain('From:')
        ->and($client->uidValidity('INBOX'))->toBeInt();

    // What inbox:reclassify reads: the header block, and only that.
    $headers = $client->fetchHeaders('INBOX', end($uids));

    expect($headers)->toContain('From:')
        ->and(substr_count(rtrim($headers), "\r\n\r\n"))->toBe(0);

    // What --reconsider-skipped does when a UID is gone: search by Message-ID.
    preg_match('/^Message-ID:\s*<([^>]+)>/mi', $headers, $id);
    if (($id[1] ?? null) !== null) {
        expect($client->findUid('INBOX', $id[1]))->toBe(end($uids));
    }

    $allMail = $client->detectAllMailFolder();
    if (str_contains((string) env('INBOX_IT_IMAP_HOST'), 'gmail')) {
        expect($allMail)->toBeString();
    }
});
