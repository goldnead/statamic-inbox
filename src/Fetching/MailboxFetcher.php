<?php

namespace Goldnead\StatamicInbox\Fetching;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicInbox\Contracts\MailboxClient;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Events\InboxMessageReceived;
use Goldnead\StatamicInbox\Filtering\BulkDetector;
use Goldnead\StatamicInbox\Filtering\Relevance;
use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\FetchFailure;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;
use Goldnead\StatamicInbox\Parsing\HtmlCleaner;
use Goldnead\StatamicInbox\Parsing\MessageParser;
use Goldnead\StatamicInbox\Parsing\ParsedMessage;
use Goldnead\StatamicInbox\Parsing\QuoteStripper;
use Goldnead\StatamicInbox\Support\MessageIds;
use Goldnead\StatamicInbox\Support\Redactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Pulls new mail of one mailbox into conversations.
 *
 * Per folder (INBOX, then Sent) the UIDs above the last one seen, or on a
 * first run everything since `import_since`. A message whose Message-ID the
 * mailbox already holds is skipped, which is also how a reply sent from the
 * inbox is recognised when it turns up in Sent.
 *
 * Failure is contained at the smallest level it happens on:
 *  - one message: recorded in `inbox_fetch_failures`, the cursor moves on,
 *    it is retried on the next runs and given up after three attempts;
 *  - one folder: recorded in `folder_errors`, the other folders still run;
 *  - the whole mailbox (no client, or no folder readable): `last_error`
 *    with scope `mailbox`, and {@see FetchFailed} is thrown.
 * A folder whose UIDVALIDITY changed starts over from `import_since`; the
 * Message-ID dedupe keeps that from storing anything twice.
 *
 * Runs inside the mailbox's brand: a fetch has no request and so no brand of
 * its own, and everything it writes has to land in the mailbox's.
 */
class MailboxFetcher
{
    /** @var list<string> problems of this run below the mailbox level */
    protected array $issues = [];

    public function __construct(
        protected MailboxClientFactory $clients,
        protected MessageParser $parser,
        protected HtmlCleaner $html,
        protected QuoteStripper $quotes,
        protected Threader $threader,
        protected LeadHubContacts $contacts,
        protected BulkDetector $bulk,
        protected Relevance $relevance,
    ) {}

    /** @return int the number of messages stored */
    public function fetch(Mailbox $mailbox): int
    {
        return BrandContext::runFor((int) $mailbox->brand_id, fn () => $this->fetchInBrand($mailbox));
    }

    protected function fetchInBrand(Mailbox $mailbox): int
    {
        $this->issues = [];
        $stored = 0;
        $folderErrors = [];

        try {
            $client = $this->clients->for($mailbox);
        } catch (Throwable $e) {
            $this->failMailbox($mailbox, Redactor::message($e, $mailbox), []);
        }

        foreach ($this->folders($mailbox) as $folder => [$cursor, $validity]) {
            try {
                $stored += $this->fetchFolder($mailbox, $client, $folder, $cursor, $validity);
            } catch (Throwable $e) {
                $folderErrors[$folder] = mb_substr(Redactor::message($e, $mailbox), 0, 1000);
            }
        }

        if (count($folderErrors) === count($this->folders($mailbox))) {
            $this->failMailbox($mailbox, (string) reset($folderErrors), $folderErrors);
        }

        [$error, $scope] = match (true) {
            $folderErrors !== [] => [
                collect($folderErrors)->map(fn ($message, $folder) => "{$folder}: {$message}")->implode(' | '),
                'folder',
            ],
            $this->issues() !== [] => [implode(' | ', $this->issues()), 'message'],
            default => [null, null],
        };

        $mailbox->forceFill([
            'last_fetched_at' => Carbon::now(),
            'last_error' => $error === null ? null : mb_substr($error, 0, 2000),
            'last_error_scope' => $scope,
            'folder_errors' => $folderErrors === [] ? null : $folderErrors,
        ])->save();

        return $stored;
    }

    /**
     * What went wrong below the folder level during this run (filled by
     * recordFailure(), read after the folders are done).
     *
     * @return list<string>
     */
    protected function issues(): array
    {
        return $this->issues;
    }

    /**
     * @param  array<string, string>  $folderErrors
     *
     * @throws FetchFailed
     */
    protected function failMailbox(Mailbox $mailbox, string $message, array $folderErrors): never
    {
        $mailbox->forceFill([
            'last_error' => mb_substr($message, 0, 2000),
            'last_error_scope' => 'mailbox',
            'folder_errors' => $folderErrors === [] ? null : $folderErrors,
        ])->save();

        throw new FetchFailed($message);
    }

    /** @return array<string, array{0: string, 1: string}> folder => [cursor column, uidvalidity column] */
    protected function folders(Mailbox $mailbox): array
    {
        $folders = [$mailbox->inbox_folder ?: 'INBOX' => ['last_uid_inbox', 'uidvalidity_inbox']];

        if ($mailbox->sent_folder) {
            $folders[$mailbox->sent_folder] = ['last_uid_sent', 'uidvalidity_sent'];
        }

        return $folders;
    }

    protected function fetchFolder(Mailbox $mailbox, MailboxClient $client, string $folder, string $cursor, string $validityColumn): int
    {
        $validity = $client->uidValidity($folder);

        if ($validity !== null && $mailbox->{$validityColumn} !== null && (int) $mailbox->{$validityColumn} !== $validity) {
            // Renumbered: every stored UID for this folder now means nothing.
            $mailbox->{$cursor} = 0;
            FetchFailure::query()->where('mailbox_id', $mailbox->id)->where('folder', $folder)->delete();
        }

        $mailbox->forceFill([$validityColumn => $validity, $cursor => (int) $mailbox->{$cursor}])->save();

        $sent = $cursor === 'last_uid_sent';
        $stored = $this->retryFailures($mailbox, $client, $folder, $sent);

        $after = (int) $mailbox->{$cursor};
        $since = $after === 0 ? $mailbox->import_since : null;

        foreach ($client->uidsAfter($folder, $after, $since) as $uid) {
            if ($this->attempt($mailbox, $client, $folder, $uid, $sent)) {
                $stored++;
            }

            $mailbox->forceFill([$cursor => max($uid, (int) $mailbox->{$cursor})])->save();
        }

        return $stored;
    }

    protected function retryFailures(Mailbox $mailbox, MailboxClient $client, string $folder, bool $sent): int
    {
        $stored = 0;

        $failures = FetchFailure::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('folder', $folder)
            ->whereNull('gave_up_at')
            ->orderBy('uid')
            ->get();

        foreach ($failures as $failure) {
            if ($this->attempt($mailbox, $client, $folder, $failure->uid, $sent)) {
                $stored++;
            }
        }

        return $stored;
    }

    /** One UID, contained: a failure is recorded and never escapes. */
    protected function attempt(Mailbox $mailbox, MailboxClient $client, string $folder, int $uid, bool $sent): bool
    {
        try {
            $stored = $this->store($mailbox, $client->fetchRaw($folder, $uid), $folder, $uid, $sent);
        } catch (Throwable $e) {
            $this->recordFailure($mailbox, $folder, $uid, Redactor::message($e, $mailbox));

            return false;
        }

        FetchFailure::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('folder', $folder)
            ->where('uid', $uid)
            ->delete();

        return $stored;
    }

    protected function recordFailure(Mailbox $mailbox, string $folder, int $uid, string $error): void
    {
        $now = Carbon::now();

        $failure = FetchFailure::query()->firstOrNew([
            'mailbox_id' => $mailbox->id,
            'folder' => $folder,
            'uid' => $uid,
        ]);

        $failure->attempts = $failure->exists ? $failure->attempts + 1 : 1;
        $failure->first_seen_at ??= $now;
        $failure->last_seen_at = $now;
        $failure->error = mb_substr($error, 0, 2000);

        if ($failure->attempts >= FetchFailure::MAX_ATTEMPTS) {
            $failure->gave_up_at = $now;
        }

        $failure->save();

        $this->issues[] = $failure->gave_up_at
            ? "{$folder}, UID {$uid}: gave up after {$failure->attempts} attempts: {$failure->error}"
            : "{$folder}, UID {$uid}: {$failure->error}";
    }

    protected function store(Mailbox $mailbox, string $raw, string $folder, int $uid, bool $sentFolder): bool
    {
        $parsed = $this->parser->parse($raw);
        $key = MessageIds::key($parsed->messageId);

        if ($this->known($mailbox, $key)) {
            return false;
        }

        $own = $mailbox->ownAddresses();
        $outgoing = $sentFolder || in_array($parsed->fromEmail, $own, true);
        $counterpart = $outgoing ? $this->recipient($parsed, $own) : $parsed->fromEmail;

        $skip = $this->skipReason($mailbox, $parsed, $outgoing, $counterpart, $own);

        if ($skip !== null) {
            $this->recordSkip($mailbox, $key, $folder, $uid, $counterpart, $skip);

            return false;
        }

        $cleaned = $this->html->clean($parsed->html);

        // A Date header from the future would keep a conversation on top of
        // the list for years; for ordering, nothing is newer than now.
        $now = Carbon::now();
        $sentAt = $parsed->sentAt ? Carbon::instance($parsed->sentAt) : $now;
        $sentAt = $sentAt->greaterThan($now) ? $now : $sentAt;

        $written = [];

        try {
            $message = DB::transaction(function () use ($mailbox, $parsed, $key, $folder, $uid, $outgoing, $counterpart, $sentAt, $cleaned, &$written) {
                $conversation = $this->threader->find($mailbox, $parsed, $counterpart)
                    ?? $this->open($mailbox, $parsed, $counterpart, $sentAt);

                $message = Message::create([
                    'mailbox_id' => $mailbox->id,
                    'conversation_id' => $conversation->id,
                    'direction' => $outgoing ? Message::OUT : Message::IN,
                    'message_id' => $key,
                    'message_id_full' => MessageIds::isHashed($parsed->messageId) ? $parsed->messageId : null,
                    'in_reply_to' => $parsed->inReplyTo === null ? null : MessageIds::key($parsed->inReplyTo),
                    'references' => implode(' ', $parsed->references) ?: null,
                    'from_email' => MessageIds::fit($parsed->fromEmail),
                    'from_name' => MessageIds::fit($parsed->fromName),
                    'to' => $parsed->to,
                    'cc' => $parsed->cc,
                    'subject' => MessageIds::fit($parsed->subject),
                    'text' => $parsed->text,
                    'html_sanitized' => $cleaned['html'],
                    'body_stripped' => $this->quotes->strip($parsed->text),
                    'sent_at' => $sentAt,
                    'folder' => MessageIds::fit($folder),
                    'imap_uid' => $uid,
                    'has_remote_images' => $cleaned['has_remote_images'],
                    'filter_headers' => $parsed->filterHeaders,
                ]);

                $this->storeAttachments($message, $parsed, $written);
                $this->touch($conversation, $message);

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // Another run stored it a moment ago.
            $this->forget($written);

            return false;
        } catch (Throwable $e) {
            $this->forget($written);

            throw $e;
        }

        if ($message->direction === Message::IN) {
            event(new InboxMessageReceived($message));
        }

        return true;
    }

    /** Stored, or deliberately left out: either way not again. */
    protected function known(Mailbox $mailbox, string $key): bool
    {
        return Message::query()->where('mailbox_id', $mailbox->id)->where('message_id', $key)->exists()
            || SkippedMessage::query()->where('mailbox_id', $mailbox->id)->where('message_id', $key)->exists();
    }

    /**
     * The other side of an outgoing mail: the first recipient that is none
     * of our own addresses. Empty for a mail to yourself.
     *
     * @param  list<string>  $own
     */
    protected function recipient(ParsedMessage $parsed, array $own): string
    {
        foreach ([...$parsed->to, ...$parsed->cc, ...$parsed->bcc] as $address) {
            if (! in_array($address['email'], $own, true)) {
                return $address['email'];
            }
        }

        return '';
    }

    /**
     * Why this mail is left out, or null to store it. See the filter spec
     * (TASKS/inbox-filter-spec-2026-09-25.md), stage 1.
     *
     * @param  list<string>  $own
     */
    protected function skipReason(Mailbox $mailbox, ParsedMessage $parsed, bool $outgoing, string $counterpart, array $own): ?string
    {
        // Mail to yourself (or between your own aliases) is no conversation.
        if ($counterpart === '' || in_array($counterpart, $own, true)) {
            return 'self';
        }

        foreach ($mailbox->blockRules as $rule) {
            if ($rule->matches($counterpart)) {
                return 'blocked';
            }
        }

        if (! $mailbox->skip_bulk) {
            return null;
        }

        if ($outgoing) {
            return $this->bulk->outgoingReason($parsed->filterHeaders, $own);
        }

        $reason = $this->bulk->reason($parsed->filterHeaders);

        // The exceptions always win: a contact, or a reply in a conversation
        // that already exists (a customer writing through a ticket system).
        if ($reason !== null && ($this->contacts->idFor($counterpart) !== null || $this->threader->byHeaders($mailbox, $parsed) !== null)) {
            return null;
        }

        return $reason;
    }

    protected function recordSkip(Mailbox $mailbox, string $key, string $folder, int $uid, string $counterpart, string $reason): void
    {
        try {
            SkippedMessage::query()->create([
                'mailbox_id' => $mailbox->id,
                'folder' => MessageIds::fit($folder, 191),
                'uid' => $uid,
                'message_id' => $key,
                'sender' => $counterpart === '' ? null : MessageIds::fit($counterpart),
                'reason' => $reason,
                'skipped_at' => Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another run recorded it a moment ago.
        }
    }

    protected function open(Mailbox $mailbox, ParsedMessage $parsed, string $counterpart, Carbon $sentAt): Conversation
    {
        return Conversation::create([
            'mailbox_id' => $mailbox->id,
            'subject' => MessageIds::fit($parsed->subject),
            'counterpart_email' => MessageIds::fit($counterpart),
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

        $relevant = $this->relevance->isRelevant($conversation);

        if ($newest) {
            $conversation->last_message_at = $message->sent_at ?? Carbon::now();

            if ($message->direction === Message::IN) {
                // A first contact from someone unknown waits in "Neu".
                $conversation->status = $relevant ? Conversation::STATUS_OPEN : Conversation::STATUS_NEW;
                $conversation->unread = true;
                $conversation->snoozed_until = null;
            } else {
                $conversation->status = Conversation::STATUS_WAITING;
                $conversation->unread = false;
            }
        } elseif ($conversation->status === Conversation::STATUS_NEW && $relevant) {
            // An older message that makes it relevant (your own answer,
            // imported late) takes it out of "Neu".
            $conversation->status = Conversation::STATUS_OPEN;
        }

        $conversation->save();
    }

    /**
     * @param  list<string>  $written  paths put on the disk so far, for clean-up
     */
    protected function storeAttachments(Message $message, ParsedMessage $parsed, array &$written): void
    {
        if ($parsed->attachments === []) {
            return;
        }

        $disk = Storage::disk((string) config('inbox.attachments.disk', 'local'));
        $base = trim((string) config('inbox.attachments.path', 'inbox/attachments'), '/').'/'.$message->id;

        foreach ($parsed->attachments as $index => $attachment) {
            $path = $base.'/'.($index + 1).'-'.$attachment['filename'];

            // put() answers false instead of throwing on most drivers; a row
            // pointing at a file that is not there is a download that 404s.
            if (! $disk->put($path, $attachment['content'])) {
                throw new RuntimeException("Could not store attachment {$attachment['filename']} on the inbox disk.");
            }

            $written[] = $path;

            Attachment::create([
                'message_id' => $message->id,
                'filename' => MessageIds::fit($attachment['filename']),
                'mime' => MessageIds::fit($attachment['mime']),
                'content_id' => $attachment['content_id'] === null ? null : MessageIds::fit($attachment['content_id'], 191),
                'size' => strlen($attachment['content']),
                'path' => $path,
            ]);
        }
    }

    /** @param  list<string>  $paths */
    protected function forget(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        try {
            Storage::disk((string) config('inbox.attachments.disk', 'local'))->delete($paths);
        } catch (Throwable) {
            // Orphaned files on a private disk are clutter, not a leak.
        }
    }
}
