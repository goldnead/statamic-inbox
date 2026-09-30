<?php

namespace Goldnead\StatamicInbox\Console\Commands;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\FetchFailure;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Support\ConversationQuery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "What lies in the inbox?" — per mailbox, for an agent over SSH.
 *
 * Counts only by default; `--details` adds the first five conversations
 * behind each number (subject, other side, age), never a message body.
 *
 * Read only: no write, no event, no log line. Above all it does not touch
 * `unread` — a summary that marked mail as read would hide exactly what it
 * was asked to report. The numbers come from {@see ConversationQuery}, the
 * class the listing's tabs use, so "open" here is "Offen" there.
 *
 * Every mailbox of every brand, unless --brand narrows it: a console run has
 * no current brand, so the queries go across brands on purpose and each
 * mailbox names the brand it belongs to.
 */
class InboxSummary extends Command
{
    public const DETAIL_LIMIT = 5;

    public const COUNTS = ['new', 'open', 'open_unread', 'waiting', 'waiting_over_days', 'snoozed', 'snoozed_due_today'];

    protected $signature = 'inbox:summary
        {--json : Print JSON instead of text}
        {--details : Add the first five conversations behind each number}
        {--wartet=7 : Days after which a conversation in "Wartet" counts as waiting too long}
        {--brand= : Only this brand (handle or id)}';

    protected $description = 'Counts per mailbox: new, open and unread, waiting too long, snoozed until today, fetch problems. Read only.';

    public function handle(): int
    {
        $filter = $this->option('brand') ?: null;
        $brand = null;

        if ($filter !== null) {
            $brand = Brand::query()->where('handle', $filter)->orWhere('id', $filter)->first();

            if (! $brand instanceof Brand) {
                return $this->refuse("Unknown brand [{$filter}].");
            }
        }

        $days = max(0, (int) $this->option('wartet'));
        $now = Carbon::now();

        $brands = Brand::query()->pluck('handle', 'id');

        $mailboxes = Mailbox::query()
            ->acrossBrands()
            ->when($brand, fn ($query) => $query->where('brand_id', $brand->id))
            ->orderBy('id')
            ->get()
            ->map(fn (Mailbox $mailbox) => $this->mailbox($mailbox, $brands[$mailbox->brand_id] ?? null, $days, $now))
            ->values()
            ->all();

        $totals = [];
        foreach (self::COUNTS as $key) {
            $totals[$key] = array_sum(array_map(fn (array $m) => $m['counts'][$key], $mailboxes));
        }
        $totals['mailboxes_with_problems'] = count(array_filter($mailboxes, fn (array $m) => $m['problems']['has_problems']));

        $result = [
            'generated_at' => $now->toIso8601String(),
            'multi_brand' => BrandContext::multiBrandEnabled(),
            'brand' => $brand?->handle,
            'waiting_days' => $days,
            'mailboxes' => $mailboxes,
            'totals' => $totals,
        ];

        $this->option('json') ? $this->printJson($result) : $this->printText($result);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    protected function mailbox(Mailbox $mailbox, ?string $brand, int $days, Carbon $now): array
    {
        /** @var array<string, \Closure(): Builder<Conversation>> $queries */
        $queries = [
            'new' => fn () => ConversationQuery::inTab($this->conversations($mailbox), 'new', $now),
            'open' => fn () => ConversationQuery::inTab($this->conversations($mailbox), 'open', $now),
            'open_unread' => fn () => ConversationQuery::inTab($this->conversations($mailbox), 'open', $now)->where('unread', true),
            'waiting' => fn () => ConversationQuery::inTab($this->conversations($mailbox), 'waiting', $now),
            'waiting_over_days' => fn () => ConversationQuery::waitingLongerThan($this->conversations($mailbox), $days, $now),
            'snoozed' => fn () => ConversationQuery::inTab($this->conversations($mailbox), 'snoozed', $now),
            'snoozed_due_today' => fn () => ConversationQuery::snoozedUntilToday($this->conversations($mailbox), $now),
        ];

        $counts = array_map(fn (\Closure $query) => $query()->count(), $queries);

        $details = null;
        if ($this->option('details')) {
            $details = [];
            foreach ($queries as $key => $query) {
                $details[$key] = $counts[$key] === 0 ? [] : $query()
                    // The longest wait first where waiting is the point.
                    ->orderBy('last_message_at', str_starts_with($key, 'waiting') ? 'asc' : 'desc')
                    ->orderBy('id')
                    ->limit(self::DETAIL_LIMIT)
                    ->get(['id', 'subject', 'counterpart_email', 'last_message_at'])
                    ->map(fn (Conversation $c) => [
                        'id' => $c->id,
                        'subject' => $c->subject,
                        'counterpart_email' => $c->counterpart_email,
                        'last_message_at' => $c->last_message_at?->toIso8601String(),
                        'age_days' => $c->last_message_at ? (int) floor(abs($c->last_message_at->diffInDays($now))) : null,
                    ])
                    ->values()
                    ->all();
            }
        }

        $givenUp = FetchFailure::query()
            ->acrossBrands()
            ->where('mailbox_id', $mailbox->id)
            ->whereNotNull('gave_up_at')
            ->count();

        $folderErrors = (array) ($mailbox->folder_errors ?? []);

        return [
            'id' => $mailbox->id,
            'name' => $mailbox->name,
            'email' => $mailbox->email,
            'brand' => $brand,
            'active' => (bool) $mailbox->active,
            'last_fetched_at' => $mailbox->last_fetched_at?->toIso8601String(),
            'counts' => $counts,
            'problems' => [
                'has_problems' => filled($mailbox->last_error) || $folderErrors !== [] || $givenUp > 0,
                'last_error' => $mailbox->last_error,
                'last_error_scope' => $mailbox->last_error_scope,
                'folder_errors' => $folderErrors,
                'failures_given_up' => $givenUp,
            ],
            'details' => $details,
        ];
    }

    /** @return Builder<Conversation> */
    protected function conversations(Mailbox $mailbox): Builder
    {
        return Conversation::query()->acrossBrands()->where('mailbox_id', $mailbox->id);
    }

    /** @param array<string, mixed> $result */
    protected function printJson(array $result): void
    {
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $result */
    protected function printText(array $result): void
    {
        $line = fn (array $c) => sprintf(
            '%d new · %d open (%d unread) · %d waiting > %d days · %d snoozed until today',
            $c['new'], $c['open'], $c['open_unread'], $c['waiting_over_days'], $result['waiting_days'], $c['snoozed_due_today'],
        );

        if ($result['mailboxes'] === []) {
            $this->line('No mailboxes.');

            return;
        }

        foreach ($result['mailboxes'] as $m) {
            $brand = $m['brand'] && $result['multi_brand'] ? " [{$m['brand']}]" : '';
            $this->line("{$m['name']} <{$m['email']}>{$brand}: ".$line($m['counts']));

            $p = $m['problems'];
            if ($p['has_problems']) {
                $issues = array_filter([
                    $p['last_error'] ? 'fetch error: '.$p['last_error'] : null,
                    $p['folder_errors'] ? 'folder errors: '.implode(', ', array_keys($p['folder_errors'])) : null,
                    $p['failures_given_up'] ? $p['failures_given_up'].' message(s) given up on' : null,
                ]);
                $this->line('  problem: '.implode('; ', $issues));
            }

            foreach ((array) $m['details'] as $key => $rows) {
                foreach ($rows as $row) {
                    $this->line("  {$key}: {$row['subject']} — {$row['counterpart_email']} — {$row['age_days']} d");
                }
            }
        }

        $this->line('Total: '.$line($result['totals']).($result['totals']['mailboxes_with_problems'] ? " · {$result['totals']['mailboxes_with_problems']} mailbox(es) with problems" : ''));
    }

    protected function refuse(string $message): int
    {
        $this->option('json')
            ? $this->printJson(['error' => $message])
            : $this->error($message);

        return self::FAILURE;
    }
}
