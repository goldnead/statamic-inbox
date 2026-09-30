<?php

namespace Goldnead\StatamicInbox\Support;

use Goldnead\StatamicInbox\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What "Offen", "Wartet", "Geschlummert" and "ungelesen" mean, in one place.
 *
 * The listing's tabs, the unread badge in the nav and `inbox:summary` all ask
 * these questions. Before this class the tab logic lived in the controller,
 * and a second reader would have had to copy it — the day one copy learned
 * something the other did not, the agent's numbers and the screen's would
 * disagree without either being visibly wrong.
 */
class ConversationQuery
{
    /** The listing tabs, in the order the screen shows them. */
    public const TABS = ['open', 'waiting', 'closed', 'snoozed', 'new'];

    /**
     * Narrow a query to one tab. A snoozed conversation belongs to
     * "snoozed" until the snooze ends, then back to its status tab; a first
     * contact ("new") is never snoozed.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public static function inTab(Builder $query, string $tab, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        if ($tab === 'snoozed') {
            return $query->where('snoozed_until', '>', $now)->where('status', '!=', Conversation::STATUS_NEW);
        }

        return $query->where('status', $tab)
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $now));
    }

    /**
     * Unread and relevant: what the nav badge counts. First contacts have
     * their own count on the "Neu" tab.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public static function unread(Builder $query): Builder
    {
        return $query->where('unread', true)->where('status', '!=', Conversation::STATUS_NEW);
    }

    /**
     * In "Wartet" and nothing has moved for longer than $days: the other side
     * has not answered.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public static function waitingLongerThan(Builder $query, int $days, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return self::inTab($query, 'waiting', $now)->where('last_message_at', '<', $now->copy()->subDays($days));
    }

    /**
     * Snoozed, and the snooze ends before the day is over — back in the
     * inbox today. "Today" is the app timezone's day.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public static function snoozedUntilToday(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return self::inTab($query, 'snoozed', $now)->where('snoozed_until', '<=', $now->copy()->endOfDay());
    }
}
