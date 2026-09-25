<?php

/*
 * What the CP shows of the filter: the "Neu" tab with its own count, the
 * other tabs without new conversations, and on the mailbox page the number
 * of skipped bulk mails, the blocklist and the switches.
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\StatamicInbox\Models\BlockRule;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\SkippedMessage;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();

    Contact::create(['email' => 'anna.beispiel@example.com']);

    deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '08-no-message-id.eml', '06-same-subject-other-sender.eml', '07-html-tracking.eml'],
    ]);
});

function inboxPageProps($test, string $url): array
{
    return $test->actingAs(inboxCpUser(['view inbox', 'manage inbox mailboxes']))->get($url)->viewData('page')['props'];
}

/** The rows the listing asks for (the page itself loads them as JSON). */
function inboxRows($test, string $tab): array
{
    return collect($test->actingAs(inboxCpUser(['view inbox']))->getJson('/cp/inbox?tab='.$tab)->json('data'))
        ->pluck('counterpart_email')->sort()->values()->all();
}

it('keeps new conversations out of the open tab, and counts them on their own tab', function () {
    $props = inboxPageProps($this, '/cp/inbox');

    expect(inboxRows($this, 'open'))->toBe(['anna.beispiel@example.com'])
        ->and($props['tabCounts']['open'])->toBe(1)
        ->and($props['tabCounts']['new'])->toBe(2);
});

it('lists new conversations in the Neu tab', function () {
    expect(inboxPageProps($this, '/cp/inbox?tab=new')['tab'])->toBe('new')
        ->and(inboxRows($this, 'new'))->toBe(['bob@example.org', 'carla@example.net']);
});

it('keeps new conversations out of the snoozed tab', function () {
    Conversation::query()->update(['snoozed_until' => Carbon::now()->addDay()]);

    expect(inboxRows($this, 'snoozed'))->toBe(['anna.beispiel@example.com']);
});

it('shows skipped bulk mail of the last 30 days, the blocklist and the switches on the mailbox page', function () {
    SkippedMessage::create([
        'mailbox_id' => $this->mailbox->id, 'folder' => 'INBOX', 'uid' => 99,
        'message_id' => 'old@example.org', 'reason' => 'list_header',
        'skipped_at' => Carbon::now()->subDays(40),
    ]);
    BlockRule::create(['mailbox_id' => $this->mailbox->id, 'type' => 'domain', 'value' => 'spam.example']);

    $props = inboxPageProps($this, '/cp/inbox/mailboxes/'.$this->mailbox->id.'/edit');

    expect($props['skippedBulk'])->toBe(1)
        ->and($props['rules'])->toHaveCount(1)
        ->and($props['rules'][0])->toMatchArray(['type' => 'domain', 'value' => 'spam.example'])
        ->and($props['mailbox']['skip_bulk'])->toBeTrue()
        ->and($props['mailbox']['aliases'])->toBe([]);
});

it('saves the switch and the aliases from the mailbox form', function () {
    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->patchJson('/cp/inbox/mailboxes/'.$this->mailbox->id, [
            'name' => 'Adrian', 'email' => 'adrian@goldner.test',
            'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'username' => 'adrian@goldner.test', 'password' => '',
            'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'skip_bulk' => false,
            'aliases' => ['Kontakt@goldner.test', 'chor@goldner.test'],
        ])
        ->assertSuccessful();

    $mailbox = Mailbox::find($this->mailbox->id);

    expect($mailbox->skip_bulk)->toBeFalse()
        ->and($mailbox->aliases)->toBe(['kontakt@goldner.test', 'chor@goldner.test']);
});

it('refuses an alias that is not an email address', function () {
    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->patchJson('/cp/inbox/mailboxes/'.$this->mailbox->id, [
            'name' => 'Adrian', 'email' => 'adrian@goldner.test',
            'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'username' => 'adrian@goldner.test', 'password' => '',
            'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'aliases' => ['kein alias'],
        ])
        ->assertJsonValidationErrors('aliases.0');
});
