<?php

namespace Goldnead\StatamicInbox\Scopes;

use Goldnead\StatamicInbox\Models\Mailbox;
use Statamic\Query\Scopes\Filter;

/**
 * "Postfach" in the conversation listing's filter bar. A core filter rather
 * than a select of its own, so it sits where Entries puts Status and Site,
 * shows up as a badge and is saved with a view.
 */
class InboxMailbox extends Filter
{
    public $pinned = true;

    public static function title()
    {
        return __('Mailbox');
    }

    public function fieldItems()
    {
        return [
            'mailbox' => [
                'type' => 'radio',
                'options' => $this->options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (! empty($values['mailbox'])) {
            $query->where('mailbox_id', (int) $values['mailbox']);
        }
    }

    public function badge($values)
    {
        $name = $this->options()[(int) ($values['mailbox'] ?? 0)] ?? null;

        return $name === null ? null : __('Mailbox').': '.$name;
    }

    public function visibleTo($key)
    {
        return $key === 'inbox-conversations';
    }

    /** @return array<int, string> */
    protected function options(): array
    {
        return Mailbox::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
