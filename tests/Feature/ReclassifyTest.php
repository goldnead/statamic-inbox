<?php

/*
 * Filter spec, test 8: `inbox:reclassify` cleans up what was imported before
 * the filter existed. It reads the headers again over IMAP (headers only,
 * PEEK) where none were stored, deletes bulk conversations with their files,
 * writes skip records and files unknown people under "new". --dry-run only
 * counts.
 */

use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->imap = fakeImap();

    // Imported the way 0.1 did: no filter, no stored headers.
    $this->mailbox = inboxMailbox(['skip_bulk' => false]);
    $this->client = deliverAndFetch($this->imap, $this->mailbox, [
        'INBOX' => ['01-new-thread.eml', '07-html-tracking.eml', '14-feedback-id.eml', '08-no-message-id.eml', '09-inline-image.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    // 0.1 also made a conversation of a note to yourself; the fetch no
    // longer does, so that one is put in by hand, where it sits on the server.
    $uid = $this->client->deliver('INBOX', mailFixture('22-self.eml'));
    $self = Conversation::create([
        'mailbox_id' => $this->mailbox->id,
        'subject' => 'Notiz an mich',
        'counterpart_email' => 'adrian@goldner.test',
        'last_message_at' => now(),
    ]);
    Message::create([
        'mailbox_id' => $this->mailbox->id,
        'conversation_id' => $self->id,
        'direction' => 'out',
        'message_id' => 'adrian-self-22@goldner.test',
        'from_email' => 'adrian@goldner.test',
        'subject' => 'Notiz an mich',
        'text' => 'Morgen Noten ausdrucken.',
        'sent_at' => now(),
        'folder' => 'INBOX',
        'imap_uid' => $uid,
    ]);

    Message::query()->update(['filter_headers' => null]);
    Conversation::query()->update(['status' => 'open']);
    $this->mailbox->update(['skip_bulk' => true]);

    $this->before = [
        'messages' => Message::count(),
        'conversations' => Conversation::count(),
        'attachments' => Attachment::count(),
    ];
});

function inboxState(): array
{
    return [
        'messages' => Message::count(),
        'conversations' => Conversation::count(),
        'attachments' => Attachment::count(),
    ];
}

it('changes nothing on a dry run, and says what it would do', function () {
    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('bulk conversations: 2')
        ->expectsOutputToContain('to yourself: 1')
        ->expectsOutputToContain('set to new: 1')
        ->assertSuccessful();

    expect(inboxState())->toBe($this->before)
        ->and(SkippedMessage::count())->toBe(0)
        ->and(Conversation::where('status', 'new')->count())->toBe(0)
        ->and(Message::whereNotNull('filter_headers')->count())->toBe(0);
});

it('reads the headers over IMAP, headers only', function () {
    $this->client->calls = [];

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--dry-run' => true])->assertSuccessful();

    $methods = collect($this->client->calls)->pluck('method')->unique()->values()->all();

    expect($methods)->toContain('fetchHeaders')
        ->not->toContain('fetchRaw')
        ->not->toContain('append');
});

it('cleans up on a real run', function () {
    $paths = Attachment::pluck('path')->all();

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id])->assertSuccessful();

    // Newsletter and invoice mailer gone with their messages; self-note gone.
    expect(Message::where('from_email', 'newsletter@shop.example')->exists())->toBeFalse()
        ->and(Message::where('from_email', 'rechnung@hosting.example')->exists())->toBeFalse()
        ->and(Message::where('message_id', 'adrian-self-22@goldner.test')->exists())->toBeFalse()
        ->and(SkippedMessage::pluck('reason')->sort()->values()->all())->toBe(['bulk_sender_header', 'list_header', 'self']);

    // Anna: answered, stays. Carla: unknown, new. Anna's photo mail: in
    // Anna's answered thread? No, its own; unknown on its own, but Anna is a
    // known partner (written to from Sent), so it stays open.
    expect(Conversation::where('counterpart_email', 'carla@example.net')->sole()->status)->toBe('new')
        ->and(Conversation::where('counterpart_email', 'anna.beispiel@example.com')->where('status', 'new')->count())->toBe(0);

    // Headers are stored now, so the next run needs no IMAP.
    expect(Message::whereNull('filter_headers')->whereNotNull('imap_uid')->count())->toBe(0);

    // The files of deleted conversations are gone, the others stay.
    $disk = Storage::disk(config('inbox.attachments.disk'));
    foreach (Attachment::pluck('path') as $path) {
        expect($disk->exists($path))->toBeTrue();
    }
    expect(count($paths))->toBe(Attachment::count());

    // A second run finds nothing more to do.
    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id, '--dry-run' => true])
        ->expectsOutputToContain('bulk conversations: 0')
        ->expectsOutputToContain('set to new: 0')
        ->assertSuccessful();
});

it('leaves a conversation alone when its headers cannot be read', function () {
    $this->client->breakFolder('INBOX', new RuntimeException('NO [UNAVAILABLE]'));

    $this->artisan('inbox:reclassify', ['--mailbox' => $this->mailbox->id])->assertSuccessful();

    expect(Message::where('from_email', 'newsletter@shop.example')->exists())->toBeTrue();
});
