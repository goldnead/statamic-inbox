<?php

namespace Goldnead\StatamicInbox\Integrations;

use Goldnead\StatamicInbox\Events\InboxMessageReceived;
use Goldnead\StatamicInbox\Events\InboxMessageSent;
use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything the inbox asks of goldnead/statamic-leadhub, in one place, and
 * nothing when LeadHub is not installed.
 *
 * Contacts are only ever looked up, never created: `LeadHub::ingest` creates
 * a contact for an unknown address, so every call to it here is preceded by
 * `findByEmail`. Otherwise every newsletter sender and every spam mail would
 * turn into a CRM contact.
 *
 * Referenced by class name, never imported: without LeadHub those classes do
 * not exist, and an import alone is harmless but a `::class` on a missing
 * facade in a type position is not.
 */
class LeadHubContacts
{
    public const FACADE = 'Goldnead\\Leadhub\\Facades\\LeadHub';

    public const SOURCE_EVENT = 'Goldnead\\Leadhub\\Support\\SourceEvent';

    public const NOTE_MODEL = 'Goldnead\\Leadhub\\Models\\Note';

    public function available(): bool
    {
        return class_exists(self::FACADE) && class_exists(self::SOURCE_EVENT);
    }

    /** @return array<string, mixed>|null */
    public function find(string $email): ?array
    {
        if (! $this->available() || trim($email) === '') {
            return null;
        }

        return (self::FACADE)::findByEmail($email);
    }

    public function idFor(string $email): ?int
    {
        $contact = $this->find($email);

        return $contact === null ? null : (int) $contact['id'];
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->available() ? (self::FACADE)::find($id) : null;
    }

    /**
     * The newest notes on a contact, for the AI draft.
     *
     * @return list<string>
     */
    public function notes(int $contactId, int $limit = 5): array
    {
        if (! $this->available() || ! class_exists(self::NOTE_MODEL)) {
            return [];
        }

        try {
            return (self::NOTE_MODEL)::query()
                ->where('contact_id', $contactId)
                ->latest()
                ->limit($limit)
                ->pluck('body')
                ->map(fn ($body) => (string) $body)
                ->values()
                ->all();
        } catch (Throwable) {
            // A flat-file LeadHub has no notes table; the draft does without.
            return [];
        }
    }

    public function onReceived(InboxMessageReceived $event): void
    {
        $this->record($event->message, 'inbox_email_received', __('Email received: :subject'));
    }

    public function onSent(InboxMessageSent $event): void
    {
        $this->record($event->message, 'inbox_email_sent', __('Email sent: :subject'));
    }

    /**
     * One timeline entry per message, only for a contact that already exists.
     * The dedupe key makes a retried job or a second event a no-op.
     */
    protected function record(Message $message, string $type, string $summary): void
    {
        if (! $this->available()) {
            return;
        }

        $email = $message->conversation->counterpart_email;

        try {
            if ($this->find($email) === null) {
                return;
            }

            $sourceEvent = self::SOURCE_EVENT;

            (self::FACADE)::ingest(new $sourceEvent(
                email: $email,
                type: $type,
                summary: str_replace(':subject', $message->subject !== '' ? $message->subject : '—', $summary),
                sourceType: 'inbox_message',
                sourceId: $message->id,
                dedupeKey: 'inbox:'.$message->message_id,
                occurredAt: $message->sent_at,
                payload: [
                    'conversation_id' => $message->conversation_id,
                    'direction' => $message->direction,
                    'subject' => $message->subject,
                ],
            ));
        } catch (Throwable $e) {
            // The mail is stored; a CRM that is down must not undo that.
            Log::warning('inbox: could not write the mail to the LeadHub timeline.', [
                'message' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
