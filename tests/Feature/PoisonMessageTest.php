<?php

/*
 * One message that cannot be stored must not freeze a mailbox. It is
 * recorded (folder, UID, masked error, attempts), the cursor moves past it,
 * the rest is fetched, and it is retried a few times before the fetcher
 * gives up on it visibly. Review of b441eb7, item 1 and item 5.
 */

use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\FetchFailure;
use Goldnead\StatamicInbox\Models\Message;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();
    $this->client = $this->imap->client($this->mailbox);
});

function fetchOnce($test): void
{
    try {
        app(MailboxFetcher::class)->fetch($test->mailbox->fresh());
    } catch (Throwable) {
        // Whether a partial failure throws is not what these tests are about.
    }
}

it('records a message that cannot be fetched, moves past it and fetches the rest', function () {
    $this->client->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->client->deliver('INBOX', mailFixture('06-same-subject-other-sender.eml'));
    $this->client->deliver('INBOX', mailFixture('08-no-message-id.eml'));
    $this->client->breakUid('INBOX', 2, new RuntimeException('BAD parse error in body structure, password '.INBOX_TEST_PASSWORD));

    fetchOnce($this);

    $mailbox = $this->mailbox->fresh();
    $failure = FetchFailure::sole();

    expect(Message::count())->toBe(2)
        ->and($mailbox->last_uid_inbox)->toBe(3)
        ->and($failure->mailbox_id)->toBe($mailbox->id)
        ->and($failure->folder)->toBe('INBOX')
        ->and($failure->uid)->toBe(2)
        ->and($failure->attempts)->toBe(1)
        ->and($failure->error)->toContain('BAD parse error')->not->toContain(INBOX_TEST_PASSWORD)
        ->and($failure->first_seen_at)->not->toBeNull()
        ->and($failure->gave_up_at)->toBeNull()
        ->and($mailbox->last_error)->toContain('INBOX')->toContain('UID 2')->not->toContain(INBOX_TEST_PASSWORD)
        ->and($mailbox->isBroken())->toBeFalse();
});

it('retries a failed UID on the next fetch and forgets it once it works', function () {
    $this->client->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->client->breakUid('INBOX', 1, new RuntimeException('temporary'));

    fetchOnce($this);
    expect(Message::count())->toBe(0)->and(FetchFailure::count())->toBe(1);

    $this->client->repairUid('INBOX', 1);
    fetchOnce($this);

    expect(Message::count())->toBe(1)
        ->and(FetchFailure::count())->toBe(0);
});

it('gives up on a UID after three attempts and says so', function () {
    $this->client->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->client->breakUid('INBOX', 1, new RuntimeException('always broken'));

    fetchOnce($this);
    fetchOnce($this);
    fetchOnce($this);

    $failure = FetchFailure::sole();

    expect($failure->attempts)->toBe(3)
        ->and($failure->gave_up_at)->not->toBeNull();

    $this->client->calls = [];
    fetchOnce($this);

    expect(FetchFailure::sole()->attempts)->toBe(3)
        ->and(collect($this->client->calls)->where('method', 'fetchRaw'))->toBeEmpty();
});

it('truncates an overlong subject instead of failing on it', function () {
    $subject = str_repeat('Überlanger Betreff ', 16); // 304 characters
    $raw = preg_replace('/^Subject: .*$/m', 'Subject: '.$subject, mailFixture('06-same-subject-other-sender.eml'));
    $this->client->deliver('INBOX', $raw);

    fetchOnce($this);

    expect(mb_strlen(Message::sole()->subject))->toBe(255)
        ->and(mb_strlen(Conversation::sole()->subject))->toBe(255)
        ->and(FetchFailure::count())->toBe(0);
});

it('stores an overlong Message-ID under a hash and still dedupes and threads on it', function () {
    $longId = str_repeat('a', 240).'@long.example';
    $raw = preg_replace('/^Message-ID: .*$/m', 'Message-ID: <'.$longId.'>', mailFixture('06-same-subject-other-sender.eml'));

    $this->client->deliver('INBOX', $raw);
    $this->client->deliver('INBOX', $raw);

    $reply = "In-Reply-To: <{$longId}>\n".preg_replace(
        '/^Message-ID: .*$/m',
        'Message-ID: <bob-reply@example.org>',
        preg_replace('/^Subject: .*$/m', 'Subject: Etwas ganz anderes', mailFixture('06-same-subject-other-sender.eml'))
    );
    $this->client->deliver('INBOX', $reply);

    fetchOnce($this);

    $first = Message::where('message_id', '!=', 'bob-reply@example.org')->sole();

    expect(strlen($first->message_id))->toBeLessThanOrEqual(191)
        ->and($first->message_id_full)->toBe($longId)
        ->and(Message::count())->toBe(2)
        ->and(Conversation::count())->toBe(1)
        ->and(FetchFailure::count())->toBe(0);
});

it('keeps INBOX working when only the Sent folder fails, and does not call the mailbox broken', function () {
    $this->client->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->client->breakFolder('Sent', new RuntimeException('NO [NONEXISTENT] Unknown folder Sent'));

    fetchOnce($this);

    $mailbox = $this->mailbox->fresh();

    expect(Message::count())->toBe(1)
        ->and($mailbox->isBroken())->toBeFalse()
        ->and($mailbox->last_fetched_at)->not->toBeNull()
        ->and($mailbox->folder_errors)->toHaveKey('Sent')
        ->and($mailbox->folder_errors['Sent'])->toContain('Unknown folder')
        ->and($mailbox->folder_errors)->not->toHaveKey('INBOX');
});

it('calls the mailbox broken when no folder can be read', function () {
    $this->client->failWith(new RuntimeException('Connection refused'));

    fetchOnce($this);

    $mailbox = $this->mailbox->fresh();

    expect($mailbox->isBroken())->toBeTrue()
        ->and($mailbox->last_error)->toContain('Connection refused');
});

it('clears a folder error once the folder works again', function () {
    $this->client->breakFolder('Sent', new RuntimeException('NO Unknown folder'));
    fetchOnce($this);

    unset($this->client->brokenFolders['Sent']);
    fetchOnce($this);

    expect($this->mailbox->fresh()->folder_errors ?? [])->toBe([])
        ->and($this->mailbox->fresh()->last_error)->toBeNull();
});
