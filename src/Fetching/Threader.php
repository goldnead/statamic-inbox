<?php

namespace Goldnead\StatamicInbox\Fetching;

use Carbon\CarbonImmutable;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Parsing\ParsedMessage;
use Goldnead\StatamicInbox\Support\MessageIds;
use Goldnead\StatamicInbox\Support\Subject;

/**
 * Finds the conversation a message belongs to, in the order the spec sets:
 *
 *   1. In-Reply-To names a stored message.
 *   2. One of the References does (newest first).
 *   3. Same counterpart and normalised subject, last message within 30 days.
 *
 * Null means: open a new conversation. Everything stays inside one mailbox.
 */
class Threader
{
    public function find(Mailbox $mailbox, ParsedMessage $parsed, string $counterpart): ?Conversation
    {
        return $this->byHeaders($mailbox, $parsed)
            ?? $this->byChildren($mailbox, $parsed)
            ?? $this->bySubject($mailbox, $parsed, $counterpart);
    }

    protected function byHeaders(Mailbox $mailbox, ParsedMessage $parsed): ?Conversation
    {
        foreach ($parsed->ancestors() as $id) {
            $message = Message::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('message_id', MessageIds::key($id))
                ->first();

            if ($message !== null) {
                return $message->conversation;
            }
        }

        return null;
    }

    /** A reply that arrived before the mail it answers already names this one. */
    protected function byChildren(Mailbox $mailbox, ParsedMessage $parsed): ?Conversation
    {
        $child = Message::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('in_reply_to', MessageIds::key($parsed->messageId))
            ->first();

        return $child?->conversation;
    }

    protected function bySubject(Mailbox $mailbox, ParsedMessage $parsed, string $counterpart): ?Conversation
    {
        $subject = Subject::normalize($parsed->subject);

        if ($subject === '' || $counterpart === '') {
            return null;
        }

        $at = $parsed->sentAt ?? CarbonImmutable::now();
        $days = (int) config('inbox.fetch.subject_match_days', 30);

        return Conversation::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('counterpart_email', strtolower($counterpart))
            ->where('subject_normalized', $subject)
            ->whereBetween('last_message_at', [$at->subDays($days), $at->addDays($days)])
            ->orderByDesc('last_message_at')
            ->first();
    }
}
