<?php

namespace Goldnead\StatamicInbox\Fetching;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicInbox\Contracts\MailboxClient;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Events\InboxMessageReceived;
use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Parsing\HtmlCleaner;
use Goldnead\StatamicInbox\Parsing\MessageParser;
use Goldnead\StatamicInbox\Parsing\ParsedMessage;
use Goldnead\StatamicInbox\Parsing\QuoteStripper;
use Goldnead\StatamicInbox\Support\Redactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pulls new mail of one mailbox into conversations.
 *
 * Per folder (INBOX, then Sent) the UIDs above the last one seen, or on a
 * first run everything since `import_since`. A message whose Message-ID the
 * mailbox already holds is skipped, which is also how a reply sent from the
 * inbox is recognised when it turns up in Sent. Progress is saved after every
 * message, so a fetch that dies half-way resumes where it stopped.
 *
 * Runs inside the mailbox's brand: a fetch has no request and so no brand of
 * its own, and everything it writes has to land in the mailbox's.
 */
class MailboxFetcher
{
    public function __construct(
        protected MailboxClientFactory $clients,
        protected MessageParser $parser,
        protected HtmlCleaner $html,
        protected QuoteStripper $quotes,
        protected Threader $threader,
        protected LeadHubContacts $contacts,
    ) {}

    /** @return int the number of messages stored */
    public function fetch(Mailbox $mailbox): int
    {
        return BrandContext::runFor((int) $mailbox->brand_id, fn () => $this->fetchInBrand($mailbox));
    }

    protected function fetchInBrand(Mailbox $mailbox): int
    {
        $stored = 0;

        try {
            $client = $this->clients->for($mailbox);

            foreach ($this->folders($mailbox) as $folder => $cursor) {
                $stored += $this->fetchFolder($mailbox, $client, $folder, $cursor);
            }
        } catch (Throwable $e) {
            $message = Redactor::message($e, $mailbox);

            $mailbox->forceFill(['last_error' => mb_substr($message, 0, 2000)])->save();

            throw new FetchFailed($message);
        }

        $mailbox->forceFill(['last_fetched_at' => Carbon::now(), 'last_error' => null])->save();

        return $stored;
    }

    /** @return array<string, string> folder => cursor column */
    protected function folders(Mailbox $mailbox): array
    {
        $folders = [$mailbox->inbox_folder ?: 'INBOX' => 'last_uid_inbox'];

        if ($mailbox->sent_folder) {
            $folders[$mailbox->sent_folder] = 'last_uid_sent';
        }

        return $folders;
    }

    protected function fetchFolder(Mailbox $mailbox, MailboxClient $client, string $folder, string $cursor): int
    {
        $after = (int) $mailbox->{$cursor};
        $since = $after === 0 ? $mailbox->import_since : null;
        $stored = 0;

        foreach ($client->uidsAfter($folder, $after, $since) as $uid) {
            if ($this->store($mailbox, $client->fetchRaw($folder, $uid), $folder, $uid, $cursor === 'last_uid_sent')) {
                $stored++;
            }

            $mailbox->forceFill([$cursor => max($uid, (int) $mailbox->{$cursor})])->save();
        }

        return $stored;
    }

    protected function store(Mailbox $mailbox, string $raw, string $folder, int $uid, bool $sentFolder): bool
    {
        $parsed = $this->parser->parse($raw);

        if ($this->known($mailbox, $parsed->messageId)) {
            return false;
        }

        $mine = strtolower($mailbox->email);
        $outgoing = $sentFolder || $parsed->fromEmail === $mine;
        $counterpart = $outgoing ? $this->recipient($parsed, $mine) : $parsed->fromEmail;
        $sentAt = $parsed->sentAt ? Carbon::instance($parsed->sentAt) : Carbon::now();
        $cleaned = $this->html->clean($parsed->html);

        try {
            $message = DB::transaction(function () use ($mailbox, $parsed, $folder, $uid, $outgoing, $counterpart, $sentAt, $cleaned) {
                $conversation = $this->threader->find($mailbox, $parsed, $counterpart)
                    ?? $this->open($mailbox, $parsed, $counterpart, $sentAt);

                $message = Message::create([
                    'mailbox_id' => $mailbox->id,
                    'conversation_id' => $conversation->id,
                    'direction' => $outgoing ? Message::OUT : Message::IN,
                    'message_id' => $parsed->messageId,
                    'in_reply_to' => $parsed->inReplyTo,
                    'references' => implode(' ', $parsed->references) ?: null,
                    'from_email' => $parsed->fromEmail,
                    'from_name' => $parsed->fromName,
                    'to' => $parsed->to,
                    'cc' => $parsed->cc,
                    'subject' => $parsed->subject,
                    'text' => $parsed->text,
                    'html_sanitized' => $cleaned['html'],
                    'body_stripped' => $this->quotes->strip($parsed->text),
                    'sent_at' => $sentAt,
                    'folder' => $folder,
                    'imap_uid' => $uid,
                    'has_remote_images' => $cleaned['has_remote_images'],
                ]);

                $this->storeAttachments($message, $parsed);
                $this->touch($conversation, $message);

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // Another run stored it a moment ago.
            return false;
        }

        if ($message->direction === Message::IN) {
            event(new InboxMessageReceived($message));
        }

        return true;
    }

    protected function known(Mailbox $mailbox, string $messageId): bool
    {
        return Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('message_id', $messageId)
            ->exists();
    }

    /** The other side of an outgoing mail: the first recipient that is not us. */
    protected function recipient(ParsedMessage $parsed, string $mine): string
    {
        foreach ([...$parsed->to, ...$parsed->cc] as $address) {
            if ($address['email'] !== $mine) {
                return $address['email'];
            }
        }

        return $parsed->to[0]['email'] ?? '';
    }

    protected function open(Mailbox $mailbox, ParsedMessage $parsed, string $counterpart, Carbon $sentAt): Conversation
    {
        return Conversation::create([
            'mailbox_id' => $mailbox->id,
            'subject' => $parsed->subject,
            'counterpart_email' => $counterpart,
            'contact_id' => $this->contacts->idFor($counterpart),
            'status' => Conversation::STATUS_OPEN,
            'unread' => true,
            'last_message_at' => $sentAt,
        ]);
    }

    /**
     * The newest message decides the state: incoming opens the conversation,
     * marks it unread and ends a snooze; outgoing means we are waiting.
     * An older message imported late changes nothing.
     */
    protected function touch(Conversation $conversation, Message $message): void
    {
        $newest = $conversation->last_message_at === null
            || $message->sent_at === null
            || $message->sent_at->greaterThanOrEqualTo($conversation->last_message_at)
            || $conversation->wasRecentlyCreated;

        if ($conversation->contact_id === null) {
            $conversation->contact_id = $this->contacts->idFor($conversation->counterpart_email);
        }

        if ($newest) {
            $conversation->last_message_at = $message->sent_at ?? CarbonImmutable::now();

            if ($message->direction === Message::IN) {
                $conversation->status = Conversation::STATUS_OPEN;
                $conversation->unread = true;
                $conversation->snoozed_until = null;
            } else {
                $conversation->status = Conversation::STATUS_WAITING;
                $conversation->unread = false;
            }
        }

        $conversation->save();
    }

    protected function storeAttachments(Message $message, ParsedMessage $parsed): void
    {
        if ($parsed->attachments === []) {
            return;
        }

        $disk = Storage::disk((string) config('inbox.attachments.disk', 'local'));
        $base = trim((string) config('inbox.attachments.path', 'inbox/attachments'), '/').'/'.$message->id;

        foreach ($parsed->attachments as $index => $attachment) {
            $path = $base.'/'.($index + 1).'-'.$attachment['filename'];
            $disk->put($path, $attachment['content']);

            Attachment::create([
                'message_id' => $message->id,
                'filename' => $attachment['filename'],
                'mime' => $attachment['mime'],
                'size' => strlen($attachment['content']),
                'path' => $path,
            ]);
        }
    }
}
