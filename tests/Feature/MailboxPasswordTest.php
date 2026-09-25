<?php

/*
 * Spec test 7: the mailbox password is encrypted at rest and never appears
 * in a CP response, in the log or in a stored error message.
 */

use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Mailbox;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->mailbox = inboxMailbox();

    // Everything the log receives, flattened to text, exceptions included.
    $this->logged = '';
    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->logged .= $event->message."\n".flattenForLog($event->context)."\n";
    });
});

function flattenForLog(mixed $value): string
{
    if ($value instanceof Throwable) {
        return get_class($value).': '.$value->getMessage()."\n".$value->getTraceAsString()
            .($value->getPrevious() ? "\n".flattenForLog($value->getPrevious()) : '');
    }

    if (is_array($value)) {
        return implode("\n", array_map(fn ($item) => flattenForLog($item), $value));
    }

    if (is_object($value)) {
        return method_exists($value, '__toString') ? (string) $value : json_encode($value);
    }

    return (string) json_encode($value);
}

/** What a real IMAP library says when a login fails, password and all. */
function loginFailure(): RuntimeException
{
    return new RuntimeException(
        'IMAP LOGIN failed for adrian@goldner.test with password '.INBOX_TEST_PASSWORD.': [AUTHENTICATIONFAILED]'
    );
}

it('stores the password encrypted and decrypts it on the model', function () {
    $raw = DB::table('inbox_mailboxes')->where('id', $this->mailbox->id)->value('password');

    expect($raw)->not->toContain(INBOX_TEST_PASSWORD)
        ->and(decrypt($raw, false))->toBe(INBOX_TEST_PASSWORD)
        ->and($this->mailbox->fresh()->password)->toBe(INBOX_TEST_PASSWORD);
});

it('never serialises the password', function () {
    expect($this->mailbox->fresh()->toJson())->not->toContain(INBOX_TEST_PASSWORD)
        ->and(array_key_exists('password', $this->mailbox->fresh()->toArray()))->toBeFalse();
});

it('keeps the password out of the mailbox screens', function () {
    $user = inboxCpUser(['manage inbox mailboxes']);

    $this->actingAs($user)->get('/cp/inbox/mailboxes')
        ->assertOk()
        ->assertDontSee(INBOX_TEST_PASSWORD, false)
        ->assertDontSee(encryptedPassword($this->mailbox), false);

    $this->actingAs($user)->get('/cp/inbox/mailboxes/'.$this->mailbox->id.'/edit')
        ->assertOk()
        ->assertDontSee(INBOX_TEST_PASSWORD, false)
        ->assertDontSee(encryptedPassword($this->mailbox), false);
});

function encryptedPassword(Mailbox $mailbox): string
{
    return (string) DB::table('inbox_mailboxes')->where('id', $mailbox->id)->value('password');
}

it('keeps the stored password when the form sends the placeholder back empty', function () {
    $user = inboxCpUser(['manage inbox mailboxes']);

    $this->actingAs($user)->patchJson('/cp/inbox/mailboxes/'.$this->mailbox->id, [
        'name' => 'Adrian (neu)',
        'email' => 'adrian@goldner.test',
        'imap_host' => 'imap.migadu.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'username' => 'adrian@goldner.test',
        'password' => '',
        'smtp_host' => 'smtp.migadu.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
    ])->assertSuccessful()->assertDontSee(INBOX_TEST_PASSWORD, false);

    expect($this->mailbox->fresh()->name)->toBe('Adrian (neu)')
        ->and($this->mailbox->fresh()->password)->toBe(INBOX_TEST_PASSWORD);
});

it('masks the password in a failed connection test, in the response and in the log', function () {
    $this->imap->client($this->mailbox)->failWith(loginFailure());
    fakeSmtp();

    $response = $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->postJson('/cp/inbox/mailboxes/'.$this->mailbox->id.'/test');

    expect($response->status())->toBeLessThan(500);

    $response->assertDontSee(INBOX_TEST_PASSWORD, false)
        // The operator still learns what went wrong.
        ->assertSee('AUTHENTICATIONFAILED', false);

    expect($this->logged)->not->toContain(INBOX_TEST_PASSWORD);
});

it('masks the password in last_error and in the log when a fetch fails', function () {
    $this->imap->client($this->mailbox)->failWith(loginFailure());

    try {
        app(MailboxFetcher::class)->fetch($this->mailbox->fresh());
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain(INBOX_TEST_PASSWORD);
    }

    $error = $this->mailbox->fresh()->last_error;

    expect($error)->toContain('AUTHENTICATIONFAILED')
        ->not->toContain(INBOX_TEST_PASSWORD)
        ->and($this->logged)->not->toContain(INBOX_TEST_PASSWORD);
});

it('masks the password when the inbox:fetch command reports a failure', function () {
    $this->imap->client($this->mailbox)->failWith(loginFailure());

    $this->artisan('inbox:fetch')
        ->doesntExpectOutputToContain(INBOX_TEST_PASSWORD);

    expect($this->logged)->not->toContain(INBOX_TEST_PASSWORD);
});
