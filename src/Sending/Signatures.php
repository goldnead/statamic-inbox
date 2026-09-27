<?php

namespace Goldnead\StatamicInbox\Sending;

use Goldnead\StatamicInbox\Integrations\LeadHubContacts;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Mailbox;

/**
 * The signatures of a mailbox: which one a reply gets, and how it reads.
 *
 * A signature is plain text with links; line breaks stay. The first
 * signature (in the mailbox's order) naming a LeadHub tag the contact
 * carries is picked, else the default. Two placeholders, nothing more:
 * `{{ sender.name }}` and `{{ mailbox.email }}`.
 */
class Signatures
{
    /** "Keine" in the reply form. */
    public const NONE = 'none';

    public function __construct(protected LeadHubContacts $contacts) {}

    /**
     * The one the reply form preselects, or null when the mailbox has none.
     *
     * @return array<string, mixed>|null
     */
    public function suggest(Mailbox $mailbox, Conversation $conversation): ?array
    {
        return $this->suggestion($mailbox, $conversation)[0];
    }

    /**
     * The preselected signature and the tag that picked it (null: the default).
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function suggestion(Mailbox $mailbox, Conversation $conversation): array
    {
        $signatures = $mailbox->signatureList();

        if ($signatures === []) {
            return [null, null];
        }

        $tags = $this->contactTags($conversation);

        if ($tags !== []) {
            foreach ($signatures as $signature) {
                foreach ((array) ($signature['tags'] ?? []) as $tag) {
                    if (in_array(mb_strtolower(trim((string) $tag)), $tags, true)) {
                        return [$signature, (string) $tag];
                    }
                }
            }
        }

        return [collect($signatures)->first(fn ($signature) => (bool) ($signature['default'] ?? false)) ?? $signatures[0], null];
    }

    /**
     * What the reply gets: the signature named, none on "none", the
     * suggested one when nothing is named.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(Mailbox $mailbox, Conversation $conversation, ?string $choice): ?array
    {
        if ($choice === self::NONE) {
            return null;
        }

        if ($choice === null || $choice === '') {
            return $this->suggest($mailbox, $conversation);
        }

        return collect($mailbox->signatureList())->first(fn ($signature) => $signature['id'] === $choice);
    }

    /** @param  array<string, mixed>  $signature */
    public function render(array $signature, Mailbox $mailbox): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) ($signature['body'] ?? '')));

        return (string) preg_replace_callback(
            '/\{\{\s*(sender\.name|mailbox\.email)\s*\}\}/',
            fn (array $match) => $match[1] === 'sender.name' ? $mailbox->senderName() : (string) $mailbox->email,
            $text,
        );
    }

    /**
     * Plain text as HTML: everything escaped, line breaks kept, and only
     * http(s) links made clickable. No markup from the text survives.
     */
    public static function html(string $text): string
    {
        $parts = preg_split('~(https?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]])~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';

        foreach ($parts as $index => $part) {
            $escaped = htmlspecialchars($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html .= $index % 2 === 1 ? '<a href="'.$escaped.'">'.$escaped.'</a>' : $escaped;
        }

        return nl2br($html, false);
    }

    /** @return list<string> the contact's tag names, lower case */
    protected function contactTags(Conversation $conversation): array
    {
        $contact = $conversation->contact_id
            ? $this->contacts->findById((int) $conversation->contact_id)
            : $this->contacts->find($conversation->counterpart_email);

        return array_values(array_map(
            fn ($tag) => mb_strtolower(trim((string) $tag)),
            (array) ($contact['tags'] ?? [])
        ));
    }
}
