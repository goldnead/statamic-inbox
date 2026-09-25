<?php

/*
 * Spec test 4: a reply carries Message-ID, In-Reply-To and References, goes
 * out over the mailbox's own SMTP, is APPENDed to Sent except on Gmail, and
 * is not stored a second time when the next fetch meets it in Sent.
 */

use Goldnead\StatamicInbox\Events\InboxMessageSent;
use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Sending\ReplySender;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

beforeEach(function () {
    $this->imap = fakeImap();
    $this->smtp = fakeSmtp();
});

/** Anna wrote, Adrian answered from his mail client, Anna answered again. */
function threadWithAnna($imap, $mailbox): Conversation
{
    deliverAndFetch($imap, $mailbox, [
        'INBOX' => ['01-new-thread.eml', '03-gmail-reply.eml'],
        'Sent' => ['02-sent-reply.eml'],
    ]);

    return Conversation::sole();
}

function onlySent($smtp): SentMessage
{
    $messages = $smtp->transport->messages();

    expect($messages)->toHaveCount(1);

    return $messages->first();
}

function mailHeader(Email $email, string $name): ?string
{
    return $email->getHeaders()->get($name)?->getBodyAsString();
}

it('sets Message-ID, In-Reply-To, References and the Re: subject', function () {
    $mailbox = inboxMailbox();
    $conversation = threadWithAnna($this->imap, $mailbox);

    $out = app(ReplySender::class)->send($conversation, "Hallo Anna,\n\nbis Dienstag!\n\nAdrian");

    $sent = onlySent($this->smtp);
    $email = $sent->getOriginalMessage();

    expect($email)->toBeInstanceOf(Email::class);

    // Our own id, in our own domain, and the one we stored.
    expect($sent->getMessageId())->toMatch('/^inbox\.[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}@goldner\.test$/')
        ->and($out->message_id)->toBe($sent->getMessageId());

    // In-Reply-To is the newest incoming message: Anna's Gmail reply.
    expect(mailHeader($email, 'In-Reply-To'))->toBe('<CAanna003+Zr9Lm@mail.gmail.com>');

    // References: the whole chain so far, oldest first, ending with the parent.
    expect(mailHeader($email, 'References'))->toBe(
        '<CAanna001+x7Qk2@mail.gmail.com> <adrian-out-001@goldner.test> <CAanna003+Zr9Lm@mail.gmail.com>'
    );

    // One "Re:", never "Re: Re:".
    expect($email->getSubject())->toBe('Re: Frage zum Coaching');

    expect($email->getFrom()[0]->getAddress())->toBe('adrian@goldner.test')
        ->and($email->getTo()[0]->getAddress())->toBe('anna.beispiel@example.com')
        ->and($email->getTextBody())->toContain('bis Dienstag!');

    // Sent over this mailbox's SMTP, not the application's default mailer.
    expect($this->smtp->requestedFor)->toBe([$mailbox->id]);
});

it('stores the reply at once as outgoing and sets the conversation to waiting', function () {
    Event::fake([InboxMessageSent::class]);

    $conversation = threadWithAnna($this->imap, inboxMailbox());
    $conversation->update(['unread' => true]);

    $out = app(ReplySender::class)->send($conversation, 'Bis Dienstag!');

    $conversation->refresh();

    expect($out)->toBeInstanceOf(Message::class)
        ->and($out->exists)->toBeTrue()
        ->and($out->direction)->toBe('out')
        ->and($out->conversation_id)->toBe($conversation->id)
        ->and($out->in_reply_to)->toBe('CAanna003+Zr9Lm@mail.gmail.com')
        ->and($conversation->messages()->count())->toBe(4)
        ->and($conversation->status)->toBe('waiting')
        ->and($conversation->unread)->toBeFalse();

    Event::assertDispatched(InboxMessageSent::class, fn ($event) => $event->message->is($out));
});

it('APPENDs the sent message to the Sent folder on a non-Gmail mailbox', function () {
    $mailbox = inboxMailbox();
    expect($mailbox->append_sent)->toBeTrue();

    $conversation = threadWithAnna($this->imap, $mailbox);
    $out = app(ReplySender::class)->send($conversation, 'Bis Dienstag!');

    $appended = $this->imap->client($mailbox)->appended;

    expect($appended)->toHaveCount(1)
        ->and($appended[0]['folder'])->toBe('Sent')
        ->and($appended[0]['flags'])->toContain('\\Seen')
        ->and($appended[0]['raw'])->toContain('Message-ID: <'.$out->message_id.'>')
        ->and($appended[0]['raw'])->toContain('Bis Dienstag!');
});

it('does not APPEND on Gmail, which files SMTP mail into Sent by itself', function () {
    $mailbox = gmailMailbox();
    expect($mailbox->append_sent)->toBeFalse();

    $conversation = threadWithAnna($this->imap, $mailbox);
    app(ReplySender::class)->send($conversation, 'Bis Dienstag!');

    expect($this->imap->client($mailbox)->appended)->toBe([])
        ->and($this->smtp->transport->messages())->toHaveCount(1);
});

it('lets a mailbox switch APPEND off by hand', function () {
    $mailbox = inboxMailbox(['append_sent' => false]);

    $conversation = threadWithAnna($this->imap, $mailbox);
    app(ReplySender::class)->send($conversation, 'Bis Dienstag!');

    expect($this->imap->client($mailbox)->appended)->toBe([]);
});

it('does not store the reply twice when the next fetch meets the APPENDed copy in Sent', function () {
    $mailbox = inboxMailbox();
    $conversation = threadWithAnna($this->imap, $mailbox);

    $out = app(ReplySender::class)->send($conversation, 'Bis Dienstag!');

    app(MailboxFetcher::class)->fetch($mailbox->fresh());

    expect(Message::where('message_id', $out->message_id)->count())->toBe(1)
        ->and($conversation->messages()->count())->toBe(4);
});

it('does not store the reply twice when Gmail files it into Sent itself', function () {
    $mailbox = gmailMailbox();
    $conversation = threadWithAnna($this->imap, $mailbox);

    $out = app(ReplySender::class)->send($conversation, 'Bis Dienstag!');

    // What Gmail does with a message sent over its SMTP.
    $this->imap->client($mailbox)->deliver('[Gmail]/Gesendet', onlySent($this->smtp)->toString());
    app(MailboxFetcher::class)->fetch($mailbox->fresh());

    expect(Message::where('message_id', $out->message_id)->count())->toBe(1);
});

it('keeps the draft text and reports the failure when SMTP refuses', function () {
    $mailbox = inboxMailbox();
    $conversation = threadWithAnna($this->imap, $mailbox);

    $this->smtp->transport = new class extends ArrayTransport
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('554 5.7.1 Relay access denied');
        }
    };

    try {
        app(ReplySender::class)->send($conversation, 'Bis Dienstag, wirklich!');
    } catch (Throwable) {
        // The caller may or may not see the exception; what matters is below.
    }

    $failed = Message::where('direction', 'out')->where('conversation_id', $conversation->id)
        ->where('text', 'like', '%Bis Dienstag, wirklich!%')->first();

    expect($failed)->not->toBeNull()
        ->and($failed->send_error)->toContain('Relay access denied')
        ->and($this->imap->client($mailbox)->appended)->toBe([]);
});
