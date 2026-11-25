<?php

/*
 * Failures that used to vanish (review of b441eb7, item 5): a reply that
 * went out but could not be filed into Sent, an attachment the disk refused,
 * a whole mailbox that could not be fetched.
 */

use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\FetchFailure;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;
use Goldnead\StatamicInbox\Parsing\MessageParser;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZBateson\MailMimeParser\IMessage;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->smtp = fakeSmtp();
    $this->mailbox = inboxMailbox();
});

it('keeps an APPEND failure on the message and shows it in the conversation', function () {
    deliverAndFetch($this->imap, $this->mailbox, ['INBOX' => ['01-new-thread.eml']]);
    $this->imap->client($this->mailbox)->breakFolder('Sent', new RuntimeException('NO [TRYCREATE] Sent does not exist'));

    $out = app(ReplySender::class)->send(Conversation::sole(), 'Hallo Anna');
    $out->refresh();

    expect($this->smtp->transport->messages())->toHaveCount(1)
        ->and($out->send_error)->toBeNull()
        ->and($out->filed_error)->toContain('TRYCREATE');

    $props = $this->actingAs(inboxCpUser(['view inbox']))
        ->get('/cp/inbox/conversations/'.$out->conversation_id)
        ->viewData('page')['props'];

    expect(collect($props['messages'])->firstWhere('id', $out->id)['filed_error'])->toContain('TRYCREATE');
});

it('does not leave an attachment row behind when the disk refuses the file', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturn(false);
    Storage::set((string) config('inbox.attachments.disk'), $disk);

    $client = $this->imap->client($this->mailbox);
    $client->deliver('INBOX', mailFixture('09-inline-image.eml'));
    $client->deliver('INBOX', mailFixture('01-new-thread.eml'));

    try {
        app(MailboxFetcher::class)->fetch($this->mailbox->fresh());
    } catch (Throwable) {
    }

    // The message with attachments is not half-stored; it is recorded as a
    // failed UID instead, and the plain one after it still arrives.
    expect(Attachment::count())->toBe(0)
        ->and(Message::where('message_id', 'CAanna009+inline@mail.gmail.com')->exists())->toBeFalse()
        ->and(Message::where('message_id', 'CAanna001+x7Qk2@mail.gmail.com')->exists())->toBeTrue()
        ->and(FetchFailure::sole()->error)->toContain('Could not store attachment');
});

it('logs a whole-mailbox failure at error level', function () {
    $levels = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$levels) {
        if (str_contains($event->message, 'inbox')) {
            $levels[] = $event->level;
        }
    });

    $this->imap->client($this->mailbox)->failWith(new RuntimeException('Connection refused'));

    $this->artisan('inbox:fetch');

    expect($levels)->toContain('error');
});

it('exits with failure when a mailbox cannot be fetched, and still fetches the others', function () {
    $other = inboxMailbox(['email' => 'other@goldner.test', 'username' => 'other@goldner.test']);
    $this->imap->client($this->mailbox)->failWith(new RuntimeException('Connection refused'));
    $this->imap->client($other)->deliver('INBOX', mailFixture('01-new-thread.eml'));

    $this->artisan('inbox:fetch')->assertFailed();

    expect(Message::count())->toBe(1);
});

it('counts IMAP errors apart from not found and exits with failure when reconsidering', function () {
    SkippedMessage::create([
        'mailbox_id' => $this->mailbox->id, 'folder' => 'INBOX', 'uid' => 1,
        'message_id' => 'form-24@goldner.test', 'sender' => 'noreply@goldner.test',
        'reason' => 'noreply_sender', 'skipped_at' => now(),
    ]);
    $this->imap->client($this->mailbox)->failWith(new RuntimeException('Connection refused'));

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--reconsider-skipped' => true])
        ->expectsOutputToContain('IMAP errors: 1')
        ->expectsOutputToContain('not found on the server: 0')
        ->assertFailed();

    expect(SkippedMessage::count())->toBe(1);
});

it('starts the inbox cursor again when only the inbox folder changes', function () {
    $mailbox = inboxMailbox();
    $mailbox->forceFill(['last_uid_inbox' => 7, 'uidvalidity_inbox' => 3, 'last_uid_sent' => 5])->save();

    $this->actingAs(inboxCpUser(['manage inbox mailboxes']))
        ->patchJson('/cp/inbox/mailboxes/'.$mailbox->id, [
            'name' => 'Adrian', 'email' => 'adrian@goldner.test',
            'imap_host' => 'imap.migadu.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'username' => 'adrian@goldner.test', 'password' => '',
            'smtp_host' => 'smtp.migadu.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'inbox_folder' => 'Archiv',
        ])
        ->assertSuccessful();

    $mailbox->refresh();

    expect($mailbox->inbox_folder)->toBe('Archiv')
        ->and($mailbox->last_uid_inbox)->toBe(0)
        ->and($mailbox->uidvalidity_inbox)->toBeNull();
});

it('logs an attachment without a stream that it skips', function () {
    Log::spy();

    $part = Mockery::mock();
    $part->shouldReceive('getBinaryContentStream')->andReturn(null);
    $part->shouldReceive('getFilename')->andReturn('rechnung-2026.pdf');

    $message = Mockery::mock(IMessage::class);
    $message->shouldReceive('getAllAttachmentParts')->andReturn([$part]);
    $message->shouldReceive('getHeaderValue')->andReturn('<abc@example.test>');

    $method = new ReflectionMethod(MessageParser::class, 'attachments');

    expect($method->invoke(app(MessageParser::class), $message))->toBe([]);

    Log::shouldHaveReceived('warning')->once();
});
