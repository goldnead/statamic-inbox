<?php

/*
 * Failures that said nothing (Befundbericht 25.11.2026, items 15, 19, 20): a
 * mailbox id that matches nothing exited 0, an AI call that could not be made
 * left no trace in the log, and a counter in the navigation swallowed its
 * error without a word.
 */

use Goldnead\StatamicInbox\Ai\DraftSuggester;
use Goldnead\StatamicInbox\Ai\DraftUnavailable;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\ServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('fails the inbox:fetch command when the given mailbox does not exist', function () {
    fakeImap();
    inboxMailbox();

    $this->artisan('inbox:fetch', ['--mailbox' => 999])
        ->assertFailed();
});

it('logs why a draft could not be requested from the AI', function () {
    config()->set('inbox.ai.api_key', 'sk-ant-test-key');

    $imap = fakeImap();
    fakeSmtp();
    $mailbox = inboxMailbox();
    $imap->client($mailbox)->deliver('INBOX', mailFixture('01-new-thread.eml'));
    $this->artisan('inbox:fetch')->assertSuccessful();
    $conversation = Conversation::sole();

    Http::fake(fn () => throw new ConnectionException('Verbindung verweigert'));
    Log::spy();

    expect(fn () => app(DraftSuggester::class)->suggest($conversation))
        ->toThrow(DraftUnavailable::class);

    Log::shouldHaveReceived('warning')->once();
});

it('logs a failed unread count and still answers 0', function () {
    Log::spy();
    // Every query fails, as a lost database connection would make it. Switched off again after
    // the call, because the rollback that follows the test runs queries too.
    $down = true;
    DB::beforeExecuting(function () use (&$down) {
        if ($down) {
            throw new RuntimeException('Datenbank nicht erreichbar');
        }
    });

    $count = ServiceProvider::unreadCount();
    $down = false;

    expect($count)->toBe(0);
    Log::shouldHaveReceived('warning')->once();
});
