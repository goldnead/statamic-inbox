<?php

namespace Goldnead\StatamicInbox\Sending;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Contracts\TransportFactory;
use Goldnead\StatamicInbox\Events\InboxMessageSent;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Support\Redactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends one stored reply over its mailbox's SMTP and files it into Sent.
 *
 * Headers are set on the Symfony message directly: Message-ID (ours, stored
 * before sending), In-Reply-To and References. The brand travels with the
 * job (brand-context's BrandOnQueue); the job also switches to the message's
 * brand itself, so it does not depend on that.
 *
 * A failure is written onto the message (`send_error`), with the password
 * masked, and the job fails; the text stays for another try.
 */
class SendReply implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Sending twice is worse than not sending: no automatic retries. */
    public int $tries = 1;

    public function __construct(public int $messageId) {}

    public function handle(TransportFactory $transports, MailboxClientFactory $clients): void
    {
        $message = Message::query()->acrossBrands()->findOrFail($this->messageId);

        BrandContext::runFor((int) $message->brand_id, fn () => $this->send($message, $transports, $clients));
    }

    protected function send(Message $message, TransportFactory $transports, MailboxClientFactory $clients): void
    {
        $mailbox = $message->mailbox;
        $email = $this->email($message);

        try {
            $sent = $transports->for($mailbox)->send($email);
        } catch (Throwable $e) {
            $error = Redactor::message($e, $mailbox);
            $message->forceFill(['send_error' => mb_substr($error, 0, 2000)])->save();

            throw new SendFailed($error);
        }

        $message->forceFill(['send_error' => null, 'sent_at' => Carbon::now()])->save();

        if ($mailbox->append_sent && $mailbox->sent_folder) {
            try {
                $clients->for($mailbox)->append($mailbox->sent_folder, $sent?->toString() ?? $email->toString(), ['\\Seen']);
            } catch (Throwable $e) {
                // The mail went out; only the copy in Sent is missing. Kept on
                // the message so the conversation can say so.
                $error = Redactor::message($e, $mailbox);
                $message->forceFill(['filed_error' => mb_substr($error, 0, 2000)])->save();

                Log::warning('inbox: reply sent, but it could not be filed into Sent.', [
                    'mailbox' => $mailbox->id,
                    'message' => $message->id,
                    'error' => $error,
                ]);
            }
        }

        event(new InboxMessageSent($message));
    }

    protected function email(Message $message): Email
    {
        $mailbox = $message->mailbox;

        $email = (new Email)
            ->from(new Address($mailbox->email, (string) $mailbox->name))
            ->subject($message->subject)
            ->text((string) $message->text)
            ->date($message->sent_at ?? Carbon::now());

        foreach ((array) $message->to as $recipient) {
            $email->addTo(new Address($recipient['email'], (string) ($recipient['name'] ?? '')));
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $message->message_id);

        // The parent is the last reference, in full; `in_reply_to` itself may
        // hold the index hash of an overlong id.
        $parentId = $message->referenceIds() !== [] ? last($message->referenceIds()) : $message->in_reply_to;

        if ($message->in_reply_to && $parentId) {
            $headers->addIdHeader('In-Reply-To', $parentId);
        }

        if ($message->referenceIds() !== []) {
            $headers->addIdHeader('References', $message->referenceIds());
        }

        $disk = Storage::disk((string) config('inbox.attachments.disk', 'local'));

        foreach ($message->attachments()->get() as $attachment) {
            /** @var Attachment $attachment */
            $email->attach((string) $disk->get($attachment->path), $attachment->filename, $attachment->mime);
        }

        return $email;
    }
}
