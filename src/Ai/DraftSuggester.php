<?php

namespace Goldnead\StatamicInbox\Ai;

use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Entwurf vorschlagen": a reply draft from the Claude Messages API.
 *
 * A small client of its own rather than statamic-automations' AI action,
 * which is not a reusable service. It reads the same `ANTHROPIC_API_KEY`.
 * The draft only ever goes back into the form; this class has no way to send.
 */
class DraftSuggester
{
    /** How many of the newest messages the model sees, and how much of each. */
    protected const MESSAGES = 6;

    protected const CHARS_PER_MESSAGE = 2000;

    public function __construct(protected LeadHubContacts $contacts) {}

    /** @throws DraftUnavailable */
    public function suggest(Conversation $conversation, ?string $instruction = null): string
    {
        $key = (string) config('inbox.ai.api_key');

        if ($key === '') {
            throw new DraftUnavailable(__('No AI key configured (ANTHROPIC_API_KEY).'));
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('inbox.ai.base_url', 'https://api.anthropic.com'), '/'))
                ->withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                ])
                ->acceptJson()
                ->timeout((int) config('inbox.ai.timeout', 60))
                ->post('/v1/messages', [
                    'model' => (string) config('inbox.ai.model'),
                    'max_tokens' => (int) config('inbox.ai.max_tokens', 1024),
                    'system' => $this->system(),
                    'messages' => [['role' => 'user', 'content' => $this->prompt($conversation, $instruction)]],
                ]);
        } catch (Throwable $e) {
            throw new DraftUnavailable(__('The AI could not be reached.'));
        }

        if (! $response->successful()) {
            $reason = (string) ($response->json('error.message') ?? $response->status());

            throw new DraftUnavailable(__('The AI returned an error: :reason', ['reason' => str_replace($key, '***', $reason)]));
        }

        $text = collect((array) $response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        if (trim($text) === '') {
            throw new DraftUnavailable(__('The AI returned no text.'));
        }

        return trim($text);
    }

    protected function system(): string
    {
        $style = trim((string) config('inbox.ai.style_prompt'));

        return implode("\n\n", array_filter([
            'You draft email replies for the owner of this mailbox. Write only the reply body, ready to send: '
            .'no subject line, no quoted history, no commentary. Answer in the language of the last message. '
            .'Do not invent facts, prices or dates that are not in the conversation or the notes.',
            $style !== '' ? "Style:\n".$style : null,
        ]));
    }

    protected function prompt(Conversation $conversation, ?string $instruction): string
    {
        $parts = [];

        $contact = $conversation->contact_id ? $this->contacts->findById((int) $conversation->contact_id) : null;

        if ($contact !== null) {
            $name = trim((string) ($contact['full_name'] ?? '')) ?: trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? ''));
            $parts[] = "Contact: {$name} <{$contact['email']}>";

            $notes = $this->contacts->notes((int) $contact['id']);

            if ($notes !== []) {
                $parts[] = "Notes about the contact:\n- ".implode("\n- ", $notes);
            }
        } else {
            $parts[] = 'Contact: '.$conversation->counterpart_email;
        }

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGES)
            ->get()
            ->reverse();

        $parts[] = "Conversation \"{$conversation->subject}\", oldest first:\n\n".$messages
            ->map(fn (Message $message) => sprintf(
                "[%s, %s, %s]\n%s",
                $message->direction === Message::IN ? 'from them' : 'from us',
                $message->from_name ?: $message->from_email,
                $message->sent_at?->toDateTimeString() ?? '',
                Str::limit(trim((string) ($message->body_stripped ?? $message->text)), self::CHARS_PER_MESSAGE)
            ))
            ->implode("\n\n---\n\n");

        if ($instruction !== null && trim($instruction) !== '') {
            $parts[] = 'Instruction for this reply: '.trim($instruction);
        }

        $parts[] = 'Draft the reply to the newest message.';

        return implode("\n\n", $parts);
    }
}
