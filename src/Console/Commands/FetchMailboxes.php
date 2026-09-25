<?php

namespace Goldnead\StatamicInbox\Console\Commands;

use Goldnead\StatamicInbox\Fetching\MailboxFetcher;
use Goldnead\StatamicInbox\Models\Mailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches every active mailbox of every brand, one after the other.
 *
 * Each mailbox holds its own lock, so a slow server never makes the next
 * scheduled run fetch it a second time in parallel, and a manual run never
 * collides with the scheduler. One failing mailbox does not stop the rest.
 */
class FetchMailboxes extends Command
{
    protected $signature = 'inbox:fetch {--mailbox= : Only this mailbox id}';

    protected $description = 'Fetch new mail of the inbox mailboxes over IMAP';

    public function handle(MailboxFetcher $fetcher): int
    {
        $mailboxes = Mailbox::query()
            ->acrossBrands()
            ->where('active', true)
            ->when($this->option('mailbox'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        foreach ($mailboxes as $mailbox) {
            $lock = Cache::lock('inbox:fetch:'.$mailbox->id, (int) config('inbox.fetch.lock_seconds', 900));

            if (! $lock->get()) {
                $this->line("Mailbox {$mailbox->id}: still being fetched, skipped.");

                continue;
            }

            try {
                $count = $fetcher->fetch($mailbox);
                $this->line("Mailbox {$mailbox->id}: {$count} new.");
            } catch (Throwable $e) {
                // The fetcher masks the password before its exception leaves it.
                $this->error("Mailbox {$mailbox->id}: {$e->getMessage()}");
                Log::warning('inbox: fetching a mailbox failed.', [
                    'mailbox' => $mailbox->id,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }
}
