<?php

namespace Goldnead\StatamicInbox\Integrations;

use Goldnead\StatamicInbox\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Fills the reply form from a goldnead/statamic-email-templates template.
 *
 * The contact's merge variables are built here: the suite has no shared
 * builder for them yet (a candidate to pull out, not in v1). Only the form is
 * filled; sending still takes a click.
 */
class EmailTemplateFiller
{
    public const FACADE = 'Goldnead\\EmailTemplates\\Facades\\EmailTemplates';

    public const MERGE = 'Goldnead\\EmailTemplates\\Support\\MergeVariables';

    public function __construct(protected LeadHubContacts $contacts) {}

    public const COLLECTION = 'Goldnead\\EmailTemplates\\Services\\EmailTemplateCollectionManager';

    public const BRANDS = 'Goldnead\\EmailTemplates\\Support\\Brands';

    public function available(): bool
    {
        return class_exists(self::FACADE) && class_exists(self::MERGE);
    }

    /**
     * The templates the reply form can pick from, as select options. Only the
     * managed ones (entries), in the current brand, the way resolve() finds
     * them; empty when email-templates is not installed.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        if (! $this->available()) {
            return [];
        }

        try {
            $handle = defined(self::COLLECTION.'::HANDLE') ? constant(self::COLLECTION.'::HANDLE') : 'et_templates';
            $brand = class_exists(self::BRANDS) && (self::BRANDS)::active() ? (self::BRANDS)::current() : null;
            $field = $brand === null ? null : (string) constant(self::BRANDS.'::FIELD');

            return Entry::whereCollection($handle)
                ->filter(fn ($entry) => $field === null || $entry->get($field) === $brand)
                ->map(fn ($entry) => [
                    'value' => (string) $entry->slug(),
                    'label' => (string) ($entry->get('title') ?: $entry->slug()),
                ])
                ->unique('value')
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::warning('inbox: could not list the email templates.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return array{subject: string, text: string}|null  null when there is no such template */
    public function fill(Conversation $conversation, string $slug): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $template = (self::FACADE)::resolve($slug);

        if ($template === null) {
            return null;
        }

        $data = $this->variables($conversation);
        $body = $template->plainText !== null && $template->plainText !== ''
            ? $template->plainText
            // A paragraph ends in a blank line, a <br> in a line break, as in a mail.
            : trim((string) preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags((string) preg_replace(['/<br\s*\/?>/i', '/<\/p>/i'], ["\n", "\n\n"], $template->body)))));

        return [
            'subject' => (self::MERGE)::apply($template->subject, $data, false),
            'text' => (self::MERGE)::apply($body, $data, false),
        ];
    }

    /** @return array<string, mixed> */
    public function variables(Conversation $conversation): array
    {
        $contact = $conversation->contact_id ? $this->contacts->findById($conversation->contact_id) : null;
        $firstName = $contact['first_name'] ?? null;
        $lastName = $contact['last_name'] ?? null;

        return [
            'contact' => [
                'email' => $contact['email'] ?? $conversation->counterpart_email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $contact['full_name'] ?? trim($firstName.' '.$lastName),
                'salutation' => $firstName ? __('Hallo :name', ['name' => $firstName]) : __('Hallo'),
            ],
            'conversation' => [
                'subject' => $conversation->subject,
            ],
        ];
    }
}
