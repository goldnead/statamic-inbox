<?php

namespace Goldnead\StatamicInbox\Console\Commands;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicInbox\Contracts\MailboxClient;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Filtering\BulkDetector;
use Goldnead\StatamicInbox\Filtering\ConversationPurger;
use Goldnead\StatamicInbox\Filtering\Relevance;
use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;
use Goldnead\StatamicInbox\Parsing\MessageParser;
use Goldnead\StatamicInbox\Support\MessageIds;
use Goldnead\StatamicInbox\Support\Redactor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Applies the filter to what was imported before it existed, or after a
 * rule changed.
 *
 * Per stored message it takes the stored filter headers, or reads them from
 * the server (headers only, PEEK: nothing is marked read, nothing moves).
 * Then per conversation:
 *  - to yourself: deleted, with skip records;
 *  - bulk (no answer, no contact, every message bulk): deleted with its
 *    files, with skip records;
 *  - not relevant: status `new`.
 * A conversation whose headers cannot all be read is left as it is.
 *
 * --dry-run counts and changes nothing, not even the stored headers.
 */
class ReclassifyMailboxes extends Command
{
    protected $signature = 'inbox:reclassify
        {--mailbox= : Only this mailbox id}
        {--reconsider-skipped : Look at skipped mail again and import what no longer counts as bulk}
        {--dry-run : Count only, change nothing}';

    protected $description = 'Apply the inbox filter to conversations imported earlier';

    public function handle(
        MailboxClientFactory $clients,
        MessageParser $parser,
        BulkDetector $bulk,
        Relevance $relevance,
        LeadHubContacts $contacts,
        ConversationPurger $purger,
    ): int {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->line('Dry run: nothing is changed.');
        }

        $mailboxes = Mailbox::query()
            ->acrossBrands()
            ->when($this->option('mailbox'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        foreach ($mailboxes as $mailbox) {
            if ($this->option('reconsider-skipped')) {
                $counts = BrandContext::runFor((int) $mailbox->brand_id, fn () => $this->reconsider(
                    $mailbox, $dry, $clients, $parser, app(MailboxFetcher::class)
                ));

                $this->line("Mailbox {$mailbox->id}:");
                $this->line("  skipped records checked: {$counts['checked']}");
                $this->line($dry ? "  to import: {$counts['import']}" : "  imported: {$counts['imported']}");
                $this->line("  still skipped: {$counts['still']}");
                $this->line("  not found on the server: {$counts['not_found']}");

                continue;
            }

            $counts = BrandContext::runFor((int) $mailbox->brand_id, fn () => $this->reclassify(
                $mailbox, $dry, $clients, $parser, $bulk, $relevance, $contacts, $purger
            ));

            $this->line("Mailbox {$mailbox->id}:");
            $this->line("  messages checked: {$counts['messages']}, headers read from the server: {$counts['fetched']}");
            $this->line("  bulk conversations: {$counts['bulk']} ({$counts['bulk_messages']} messages)");
            $this->line("  to yourself: {$counts['self']}");
            $this->line("  set to new: {$counts['new']}");
            $this->line("  out of new: {$counts['out_of_new']}");
            $this->line("  unchanged: {$counts['unchanged']}");
            $this->line("  unreadable, left as they are: {$counts['unreadable']}");
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    protected function reclassify(
        Mailbox $mailbox,
        bool $dry,
        MailboxClientFactory $clients,
        MessageParser $parser,
        BulkDetector $bulk,
        Relevance $relevance,
        LeadHubContacts $contacts,
        ConversationPurger $purger,
    ): array {
        $counts = array_fill_keys(['messages', 'fetched', 'bulk', 'bulk_messages', 'self', 'new', 'out_of_new', 'unchanged', 'unreadable'], 0);
        $own = $mailbox->ownAddresses();
        $client = null;

        $conversations = Conversation::query()->where('mailbox_id', $mailbox->id)->orderBy('id')->get();

        foreach ($conversations as $conversation) {
            $messages = Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
            $headers = [];
            $readable = true;

            foreach ($messages as $message) {
                $counts['messages']++;

                // Headers stored by 0.2.0 lack Reply-To; those are read again.
                if (is_array($message->filter_headers) && array_key_exists('reply_to', $message->filter_headers)) {
                    $headers[$message->id] = $message->filter_headers;

                    continue;
                }

                if ($message->imap_uid === null || ! $message->folder) {
                    // A reply sent from the inbox: personal by definition.
                    $headers[$message->id] = [];

                    continue;
                }

                try {
                    $client ??= $clients->for($mailbox);
                    $headers[$message->id] = $parser->headersOnly($this->headers($client, $message));
                    $counts['fetched']++;
                } catch (Throwable $e) {
                    $readable = false;
                    $this->warn("  message {$message->id}: ".Redactor::message($e, $mailbox));
                }
            }

            if (! $readable) {
                $counts['unreadable']++;
            }

            if ($readable && ! $dry) {
                foreach ($messages as $message) {
                    if (($headers[$message->id] ?? []) !== [] && $message->filter_headers !== $headers[$message->id]) {
                        $message->forceFill(['filter_headers' => $headers[$message->id]])->save();
                    }
                }
            }

            $counterpart = strtolower($conversation->counterpart_email);

            // Deleting needs the headers; with some unreadable (an archived
            // mail whose UID is gone) the conversation is never deleted.
            if ($readable && ($counterpart === '' || in_array($counterpart, $own, true))) {
                $counts['self']++;
                $dry || $purger->purge([$conversation], 'self');

                continue;
            }

            if ($readable && $mailbox->skip_bulk && $this->isBulk($mailbox, $conversation, $messages, $headers, $bulk, $contacts, $relevance)) {
                $counts['bulk']++;
                $counts['bulk_messages'] += $messages->count();
                $dry || $purger->purge([$conversation], fn (Message $m) => $bulk->reason($headers[$m->id] ?? []) ?? 'list_header');

                continue;
            }

            // Relevance needs no headers, so it is applied either way.
            $relevant = $relevance->isRelevant($conversation);

            if ($conversation->status !== Conversation::STATUS_NEW && ! $relevant) {
                $counts['new']++;
                $dry || $conversation->forceFill(['status' => Conversation::STATUS_NEW])->save();

                continue;
            }

            if ($conversation->status === Conversation::STATUS_NEW && $relevant) {
                $counts['out_of_new']++;
                $latestOut = Message::query()->where('conversation_id', $conversation->id)
                    ->orderByDesc('sent_at')->orderByDesc('id')->value('direction') === Message::OUT;
                $dry || $conversation->forceFill([
                    'status' => $latestOut ? Conversation::STATUS_WAITING : Conversation::STATUS_OPEN,
                ])->save();

                continue;
            }

            $counts['unchanged']++;
        }

        return $counts;
    }

    /**
     * --reconsider-skipped: every skip record except hidden senders, looked
     * at again under today's rules. The mail is found by folder and UID, or,
     * when the UID is gone (archived, moved), by its Message-ID in INBOX and
     * All Mail; that works for records 0.2.0 wrote, which still carry the
     * plain id. Headers first (PEEK); only a mail that would be imported is
     * read whole, and only on a real run. Afterwards every remaining record
     * keeps only the hash of its id and no sender.
     *
     * @return array<string, int>
     */
    protected function reconsider(Mailbox $mailbox, bool $dry, MailboxClientFactory $clients, MessageParser $parser, MailboxFetcher $fetcher): array
    {
        $counts = array_fill_keys(['checked', 'import', 'imported', 'still', 'not_found'], 0);
        $client = null;
        $allMail = false;

        $records = SkippedMessage::query()->where('mailbox_id', $mailbox->id)->where('reason', '!=', 'blocked')->orderBy('id')->get();

        foreach ($records as $record) {
            $counts['checked']++;

            try {
                $client ??= $clients->for($mailbox);
                $found = $this->locate($client, $mailbox, $record, $parser, $allMail);
            } catch (Throwable $e) {
                $this->warn("  skip record {$record->id}: ".Redactor::message($e, $mailbox));
                $found = null;
            }

            if ($found === null) {
                $counts['not_found']++;

                continue;
            }

            [$folder, $uid] = $found;
            $sent = $folder === $mailbox->sent_folder;

            try {
                // Whole only now: parsing needs the body, and PEEK keeps it unread.
                $raw = $client->fetchRaw($folder, $uid);
                $outcome = $fetcher->reconsider($mailbox, $raw, $folder, $uid, $sent, $dry);
            } catch (Throwable $e) {
                $this->warn("  skip record {$record->id}: ".Redactor::message($e, $mailbox));
                $counts['not_found']++;

                continue;
            }

            if ($outcome === 'import') {
                $counts[$dry ? 'import' : 'imported']++;
            } elseif ($outcome === 'known') {
                $dry || $record->delete();
            } else {
                $counts['still']++;
            }
        }

        if (! $dry) {
            // What stays: a hash and a reason, as the fetch writes it today.
            foreach (SkippedMessage::query()->where('mailbox_id', $mailbox->id)->get() as $record) {
                $changes = [];
                if (! str_starts_with($record->message_id, 'sha256:')) {
                    $changes['message_id'] = SkippedMessage::keyFor($record->message_id);
                }
                if ($record->reason !== 'blocked' && $record->sender !== null) {
                    $changes['sender'] = null;
                }
                if ($changes !== []) {
                    $record->forceFill($changes)->save();
                }
            }
        }

        return $counts;
    }

    /**
     * Where the skipped mail is now: its recorded UID if the Message-ID still
     * matches there, else a search by Message-ID in INBOX and All Mail.
     *
     * @return array{0: string, 1: int}|null
     */
    protected function locate(MailboxClient $client, Mailbox $mailbox, SkippedMessage $record, MessageParser $parser, string|false|null &$allMail): ?array
    {
        $matches = function (string $headers) use ($parser, $record): bool {
            $id = $parser->parse($headers)->messageId;

            return in_array($record->message_id, [SkippedMessage::keyFor($id), MessageIds::key($id)], true);
        };

        if ($record->uid !== null && $record->folder !== '') {
            try {
                if ($matches($client->fetchHeaders($record->folder, (int) $record->uid))) {
                    return [$record->folder, (int) $record->uid];
                }
            } catch (Throwable) {
                // Gone from there; search below.
            }
        }

        // A hash cannot be searched for; only a plain id from 0.2.0 can.
        if (str_starts_with($record->message_id, 'sha256:')) {
            return null;
        }

        $allMail = $allMail === false ? $client->detectAllMailFolder() : $allMail;

        foreach (array_unique(array_filter([$mailbox->inbox_folder ?: 'INBOX', $allMail])) as $folder) {
            $uid = $client->findUid($folder, $record->message_id);

            if ($uid !== null) {
                return [$folder, $uid];
            }
        }

        return null;
    }

    /**
     * Bulk means: nobody answered, the other side is no contact, and every
     * message in it is bulk by the stage-1 rules.
     *
     * @param  iterable<Message>  $messages
     * @param  array<int, array<string, mixed>>  $headers
     */
    protected function isBulk(Mailbox $mailbox, Conversation $conversation, iterable $messages, array $headers, BulkDetector $bulk, LeadHubContacts $contacts, Relevance $relevance): bool
    {
        if ($conversation->accepted_at !== null || $conversation->contact_id !== null || $contacts->idFor($conversation->counterpart_email) !== null) {
            return false;
        }

        // Someone this mailbox has written to before is never bulk mail.
        if ($relevance->knownPartner((int) $mailbox->id, $conversation->counterpart_email)) {
            return false;
        }

        $any = false;

        foreach ($messages as $message) {
            if ($message->direction === Message::OUT || $bulk->reason($headers[$message->id] ?? []) === null) {
                return false;
            }

            $any = true;
        }

        return $any;
    }

    protected function headers(MailboxClient $client, Message $message): string
    {
        return $client->fetchHeaders((string) $message->folder, (int) $message->imap_uid);
    }
}
