<?php

namespace Goldnead\StatamicInbox\Integrations;

use Goldnead\StatamicInbox\Models\Conversation;

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

    public function available(): bool
    {
        return class_exists(self::FACADE) && class_exists(self::MERGE);
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
            : trim(html_entity_decode(strip_tags((string) preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $template->body))));

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
