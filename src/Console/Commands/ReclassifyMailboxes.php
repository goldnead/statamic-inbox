<?php

namespace Goldnead\StatamicInbox\Console\Commands;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicInbox\Contracts\MailboxClient;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Filtering\BulkDetector;
use Goldnead\StatamicInbox\Filtering\ConversationPurger;
use Goldnead\StatamicInbox\Filtering\Relevance;
use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Parsing\MessageParser;
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
            $counts = BrandContext::runFor((int) $mailbox->brand_id, fn () => $this->reclassify(
                $mailbox, $dry, $clients, $parser, $bulk, $relevance, $contacts, $purger
            ));

            $this->line("Mailbox {$mailbox->id}:");
            $this->line("  messages checked: {$counts['messages']}, headers read from the server: {$counts['fetched']}");
            $this->line("  bulk conversations: {$counts['bulk']} ({$counts['bulk_messages']} messages)");
            $this->line("  to yourself: {$counts['self']}");
            $this->line("  set to new: {$counts['new']}");
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
        $counts = array_fill_keys(['messages', 'fetched', 'bulk', 'bulk_messages', 'self', 'new', 'unchanged', 'unreadable'], 0);
        $own = $mailbox->ownAddresses();
        $client = null;

        $conversations = Conversation::query()->where('mailbox_id', $mailbox->id)->orderBy('id')->get();

        foreach ($conversations as $conversation) {
            $messages = Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
            $headers = [];
            $readable = true;

            foreach ($messages as $message) {
                $counts['messages']++;

                if (is_array($message->filter_headers)) {
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

                continue;
            }

            if (! $dry) {
                foreach ($messages as $message) {
                    if (! is_array($message->filter_headers) && ($headers[$message->id] ?? []) !== []) {
                        $message->forceFill(['filter_headers' => $headers[$message->id]])->save();
                    }
                }
            }

            $counterpart = strtolower($conversation->counterpart_email);

            if ($counterpart === '' || in_array($counterpart, $own, true)) {
                $counts['self']++;
                $dry || $purger->purge([$conversation], 'self');

                continue;
            }

            if ($mailbox->skip_bulk && $this->isBulk($conversation, $messages, $headers, $bulk, $contacts)) {
                $counts['bulk']++;
                $counts['bulk_messages'] += $messages->count();
                $dry || $purger->purge([$conversation], fn (Message $m) => $bulk->reason($headers[$m->id] ?? []) ?? 'list_header');

                continue;
            }

            if ($conversation->status !== Conversation::STATUS_NEW && ! $relevance->isRelevant($conversation)) {
                $counts['new']++;
                $dry || $conversation->forceFill(['status' => Conversation::STATUS_NEW])->save();

                continue;
            }

            $counts['unchanged']++;
        }

        return $counts;
    }

    /**
     * Bulk means: nobody answered, the other side is no contact, and every
     * message in it is bulk by the stage-1 rules.
     *
     * @param  iterable<Message>  $messages
     * @param  array<int, array<string, mixed>>  $headers
     */
    protected function isBulk(Conversation $conversation, iterable $messages, array $headers, BulkDetector $bulk, LeadHubContacts $contacts): bool
    {
        if ($conversation->accepted_at !== null || $conversation->contact_id !== null || $contacts->idFor($conversation->counterpart_email) !== null) {
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
