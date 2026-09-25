<?php

/*
 * Failures that used to vanish (review of b441eb7, item 5): a reply that
 * went out but could not be filed into Sent, an attachment the disk refused,
 * a whole mailbox that could not be fetched.
 */

use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

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
        app(Goldnead\StatamicInbox\Fetching\MailboxFetcher::class)->fetch($this->mailbox->fresh());
    } catch (Throwable) {
    }

    // The message with attachments is not half-stored; it is recorded as a
    // failed UID instead, and the plain one after it still arrives.
    expect(Attachment::count())->toBe(0)
        ->and(Message::where('message_id', 'CAanna009+inline@mail.gmail.com')->exists())->toBeFalse()
        ->and(Message::where('message_id', 'CAanna001+x7Qk2@mail.gmail.com')->exists())->toBeTrue()
        ->and(Goldnead\StatamicInbox\Models\FetchFailure::sole()->error)->toContain('Could not store attachment');
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
