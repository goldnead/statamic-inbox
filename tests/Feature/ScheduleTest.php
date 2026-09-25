<?php

/*
 * `inbox:fetch` is scheduled every minute. Overlap is prevented per mailbox,
 * not only for the command as a whole, so a slow mailbox never holds up the
 * others and a manual run never fetches a mailbox the scheduler is fetching.
 */

use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

it('schedules inbox:fetch every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'inbox:fetch'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('skips a mailbox another run is still fetching, and fetches the rest', function () {
    $imap = fakeImap();
    $busy = inboxMailbox();
    $free = inboxMailbox(['email' => 'chor@goldner.test', 'username' => 'chor@goldner.test']);

    $imap->client($busy)->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $imap->client($free)->deliver('INBOX', mailFixture('06-same-subject-other-sender.eml'));

    $lock = Cache::lock('inbox:fetch:'.$busy->id, 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('inbox:fetch')->assertSuccessful();
    } finally {
        $lock->release();
    }

    expect(Message::pluck('message_id')->all())->toBe(['bob-20260923-1100@example.org']);
});

it('fetches a single mailbox with --mailbox', function () {
    $imap = fakeImap();
    $one = inboxMailbox();
    $other = inboxMailbox(['email' => 'chor@goldner.test', 'username' => 'chor@goldner.test']);

    $imap->client($one)->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $imap->client($other)->deliver('INBOX', mailFixture('06-same-subject-other-sender.eml'));

    $this->artisan('inbox:fetch', ['--mailbox' => $one->id])->assertSuccessful();

    expect(Message::pluck('message_id')->all())->toBe(['CAanna001+x7Qk2@mail.gmail.com']);
});
