<?php

namespace Goldnead\StatamicInbox\Filtering;

use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;

/**
 * Is a conversation relevant, or a first contact for the "Neu" tab?
 *
 * Relevant when one of these holds:
 *  1. the other side is a LeadHub contact;
 *  2. the conversation has an outgoing message (you answered or started it);
 *  3. this mailbox has sent a personal mail to that address before;
 *  4. someone accepted it ("Übernehmen").
 */
class Relevance
{
    public function __construct(protected LeadHubContacts $contacts) {}

    public function isRelevant(Conversation $conversation): bool
    {
        if ($conversation->accepted_at !== null || $conversation->contact_id !== null) {
            return true;
        }

        if ($this->contacts->idFor($conversation->counterpart_email) !== null) {
            return true;
        }

        if (Message::query()->where('conversation_id', $conversation->id)->where('direction', Message::OUT)->exists()) {
            return true;
        }

        return $this->knownPartner((int) $conversation->mailbox_id, $conversation->counterpart_email);
    }

    /** Has this mailbox written to that address before, in any conversation? */
    public function knownPartner(int $mailboxId, string $address): bool
    {
        if ($address === '') {
            return false;
        }

        return Message::query()
            ->where('inbox_messages.mailbox_id', $mailboxId)
            ->where('direction', Message::OUT)
            ->whereHas('conversation', fn ($q) => $q->where('counterpart_email', strtolower($address)))
            ->exists();
    }
}
