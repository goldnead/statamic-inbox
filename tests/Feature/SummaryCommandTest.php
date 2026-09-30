<?php

/*
 * `inbox:summary`: what lies in the inbox, per mailbox, for an agent over SSH.
 *
 * The numbers must be the ones the Control Panel shows: the tabs and the
 * command share one query class, so "open" here is "Offen" there. Beyond
 * that, three properties: the JSON shape is fixed because a script parses it,
 * every brand is answered for unless --brand narrows it, and the command
 * writes nothing, dispatches nothing and logs nothing — reading a summary
 * must not mark a conversation as read.
 */

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\FetchFailure;
use Goldnead\StatamicInbox\Models\Mailbox;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

const SUMMARY_COUNT_KEYS = ['new', 'open', 'open_unread', 'waiting', 'waiting_over_days', 'snoozed', 'snoozed_due_today'];

/** Run inbox:summary with --json and decode what it printed. */
function summaryJson(array $arguments = []): array
{
    $exit = Artisan::call('inbox:summary', $arguments + ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0, $output);

    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $attributes */
function conversationIn(Mailbox $mailbox, array $attributes): Conversation
{
    return Conversation::create([
        'mailbox_id' => $mailbox->id,
        'subject' => 'Frage zur Probe',
        'counterpart_email' => 'anna@example.com',
        'status' => 'open',
        'unread' => false,
        'last_message_at' => now()->subHour(),
        ...$attributes,
    ]);
}

/** One of each kind, so every number is 1 or 2 and none coincides by accident. */
function fillMailbox(Mailbox $mailbox): void
{
    conversationIn($mailbox, ['status' => 'new', 'unread' => true, 'subject' => 'Erstkontakt']);
    conversationIn($mailbox, ['status' => 'open', 'unread' => true, 'subject' => 'Ungelesen']);
    conversationIn($mailbox, ['status' => 'open', 'unread' => false, 'subject' => 'Gelesen']);
    conversationIn($mailbox, ['status' => 'waiting', 'last_message_at' => now()->subDays(2), 'subject' => 'Wartet kurz']);
    conversationIn($mailbox, ['status' => 'waiting', 'last_message_at' => now()->subDays(9), 'subject' => 'Wartet lange']);
    conversationIn($mailbox, ['status' => 'open', 'snoozed_until' => now()->addHours(3), 'subject' => 'Schlummert bis heute']);
    conversationIn($mailbox, ['status' => 'open', 'snoozed_until' => now()->addDays(3), 'subject' => 'Schlummert länger']);
    conversationIn($mailbox, ['status' => 'closed', 'subject' => 'Erledigt']);
}

it('counts each mailbox the way the tabs do', function () {
    $mailbox = inboxMailbox();
    fillMailbox($mailbox);

    $json = summaryJson();

    expect($json['mailboxes'])->toHaveCount(1)
        ->and($json['mailboxes'][0]['counts'])->toBe([
            'new' => 1,
            'open' => 2,
            'open_unread' => 1,
            'waiting' => 2,
            'waiting_over_days' => 1,
            'snoozed' => 2,
            'snoozed_due_today' => 1,
        ])
        ->and($json['totals'])->toBe([
            'new' => 1,
            'open' => 2,
            'open_unread' => 1,
            'waiting' => 2,
            'waiting_over_days' => 1,
            'snoozed' => 2,
            'snoozed_due_today' => 1,
            'mailboxes_with_problems' => 0,
        ]);
});

it('agrees with the tab counts on the Control Panel', function () {
    $mailbox = inboxMailbox();
    fillMailbox($mailbox);

    $counts = summaryJson()['mailboxes'][0]['counts'];

    $response = $this->actingAs(inboxCpUser(['view inbox']))->get('/cp/inbox');
    $response->assertOk();
    $tabs = $response->viewData('page')['props']['tabCounts'];

    expect($tabs['new'])->toBe($counts['new'])
        ->and($tabs['open'])->toBe($counts['open'])
        ->and($tabs['waiting'])->toBe($counts['waiting'])
        ->and($tabs['snoozed'])->toBe($counts['snoozed']);
});

it('takes the waiting threshold from --wartet', function () {
    $mailbox = inboxMailbox();
    fillMailbox($mailbox);

    expect(summaryJson(['--wartet' => 1])['mailboxes'][0]['counts']['waiting_over_days'])->toBe(2)
        ->and(summaryJson(['--wartet' => 1])['waiting_days'])->toBe(1)
        ->and(summaryJson(['--wartet' => 30])['mailboxes'][0]['counts']['waiting_over_days'])->toBe(0);
});

it('reports fetch problems: the mailbox error, folder errors and failures given up on', function () {
    $mailbox = inboxMailbox();
    $mailbox->forceFill([
        'last_error' => 'AUTHENTICATIONFAILED',
        'last_error_scope' => 'mailbox',
        'folder_errors' => ['Sent' => 'Folder not found'],
    ])->save();

    FetchFailure::create(['mailbox_id' => $mailbox->id, 'folder' => 'INBOX', 'uid' => 7, 'error' => 'x', 'attempts' => 3, 'gave_up_at' => now()]);
    FetchFailure::create(['mailbox_id' => $mailbox->id, 'folder' => 'INBOX', 'uid' => 8, 'error' => 'x', 'attempts' => 1]);

    $problems = summaryJson()['mailboxes'][0]['problems'];

    expect($problems)->toBe([
        'has_problems' => true,
        'last_error' => 'AUTHENTICATIONFAILED',
        'last_error_scope' => 'mailbox',
        'folder_errors' => ['Sent' => 'Folder not found'],
        'failures_given_up' => 1,
    ]);
});

it('keeps the JSON shape stable, and leaves details out unless asked', function () {
    $mailbox = inboxMailbox();
    fillMailbox($mailbox);

    $json = summaryJson();

    expect(array_keys($json))->toBe(['generated_at', 'multi_brand', 'brand', 'waiting_days', 'mailboxes', 'totals'])
        ->and(array_keys($json['mailboxes'][0]))->toBe(['id', 'name', 'email', 'brand', 'active', 'last_fetched_at', 'counts', 'problems', 'details'])
        ->and(array_keys($json['mailboxes'][0]['counts']))->toBe(SUMMARY_COUNT_KEYS)
        ->and($json['mailboxes'][0]['details'])->toBeNull()
        ->and($json['mailboxes'][0]['email'])->toBe('adrian@goldner.test')
        ->and($json['mailboxes'][0])->not->toHaveKey('password');

    $details = summaryJson(['--details' => true])['mailboxes'][0]['details'];

    expect(array_keys($details))->toBe(SUMMARY_COUNT_KEYS)
        ->and(array_keys($details['open_unread'][0]))->toBe(['id', 'subject', 'counterpart_email', 'last_message_at', 'age_days'])
        ->and($details['open_unread'][0]['subject'])->toBe('Ungelesen')
        ->and($details['waiting_over_days'][0]['subject'])->toBe('Wartet lange')
        ->and($details['waiting_over_days'][0]['age_days'])->toBe(9)
        ->and($details['snoozed_due_today'][0]['subject'])->toBe('Schlummert bis heute');
});

it('lists at most five conversations per number', function () {
    $mailbox = inboxMailbox();
    foreach (range(1, 7) as $i) {
        conversationIn($mailbox, ['status' => 'open', 'unread' => true, 'subject' => "Frage {$i}"]);
    }

    $box = summaryJson(['--details' => true])['mailboxes'][0];

    expect($box['counts']['open_unread'])->toBe(7)
        ->and($box['details']['open_unread'])->toHaveCount(5);
});

it('prints one line per mailbox and a total without --json', function () {
    $mailbox = inboxMailbox();
    fillMailbox($mailbox);

    Artisan::call('inbox:summary');
    $output = Artisan::output();

    expect($output)->toContain('adrian@goldner.test')
        ->toContain('Total');
});

it('answers for every brand, names the brand of each mailbox, and filters with --brand', function () {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $a = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $b = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    BrandContext::runFor($a, function () {
        $mailbox = inboxMailbox();
        conversationIn($mailbox, ['status' => 'open', 'unread' => true]);
    });
    BrandContext::runFor($b, function () {
        $mailbox = inboxMailbox(['email' => 'info@chor.test', 'username' => 'info@chor.test']);
        conversationIn($mailbox, ['status' => 'new']);
        conversationIn($mailbox, ['status' => 'new']);
    });

    app('brand-context')->forget();

    $json = summaryJson();

    expect($json['multi_brand'])->toBeTrue()
        ->and(collect($json['mailboxes'])->mapWithKeys(fn ($m) => [$m['email'] => [$m['brand'], $m['counts']['open_unread'], $m['counts']['new']]])->all())
        ->toBe([
            'adrian@goldner.test' => ['brand-a', 1, 0],
            'info@chor.test' => ['brand-b', 0, 2],
        ])
        ->and($json['totals']['new'])->toBe(2);

    $only = summaryJson(['--brand' => 'brand-b']);

    expect($only['brand'])->toBe('brand-b')
        ->and(collect($only['mailboxes'])->pluck('email')->all())->toBe(['info@chor.test'])
        ->and($only['totals']['open_unread'])->toBe(0);

    expect(Artisan::call('inbox:summary', ['--brand' => 'gibt-es-nicht', '--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true))->toBe(['error' => 'Unknown brand [gibt-es-nicht].']);
});

it('writes nothing, dispatches nothing and logs nothing', function () {
    $mailbox = inboxMailbox();
    fillMailbox($mailbox);
    $before = Conversation::query()->orderBy('id')->get()->map->getAttributes()->all();

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|upsert|create|alter|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $events = [];
    Event::listen('*', function (string $name) use (&$events) {
        if (str_starts_with($name, 'Goldnead\\')
            || preg_match('/^eloquent\.(creat|updat|sav|delet|restor|forceDelet)/', $name)
            || $name === MessageLogged::class) {
            $events[] = $name;
        }
    });

    Artisan::call('inbox:summary');
    Artisan::call('inbox:summary', ['--json' => true]);
    Artisan::call('inbox:summary', ['--json' => true, '--details' => true, '--wartet' => 1]);

    expect($writes)->toBe([])
        ->and($events)->toBe([])
        ->and(Conversation::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});

it('answers with an empty list when there is no mailbox', function () {
    $json = summaryJson();

    expect($json['mailboxes'])->toBe([])
        ->and($json['totals']['open'])->toBe(0);
});

it('counts relative to the moment it runs, in the app timezone', function () {
    $mailbox = inboxMailbox();
    // Chicago: 07:00 on the 25th. A snooze ending at 23:00 local is still today.
    conversationIn($mailbox, ['snoozed_until' => Carbon::parse('2026-09-25 23:00:00', 'America/Chicago')]);
    conversationIn($mailbox, ['snoozed_until' => Carbon::parse('2026-09-26 00:30:00', 'America/Chicago')]);

    expect(summaryJson()['mailboxes'][0]['counts']['snoozed_due_today'])->toBe(1);
});
