<?php

namespace Goldnead\StatamicInbox\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * What an operator may change for the inbox from the Control Panel, per brand.
 *
 * Storing, validating, the screen and the config override all live in
 * goldnead/statamic-brand-context; this class only declares the fields.
 *
 * Secrets are not here and never will be: the mailbox password sits encrypted
 * on its mailbox row, the AI key in the environment. A setting ends up in a
 * backup and on a screen, which is exactly where a password must not.
 */
class Settings implements ProvidesSettings
{
    /** Stable forever: written into `brand_settings.namespace` on every row. */
    public static function settingsNamespace(): string
    {
        return 'inbox';
    }

    public static function settingsConfigPath(): string
    {
        return 'inbox';
    }

    public static function settingsPermission(): string
    {
        return 'manage inbox mailboxes';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('Replies'),
                'description' => __('How the reply form is prepared. Nothing here sends a mail; every reply still needs a click.'),
                'fields' => [
                    [
                        'key' => 'default_mailbox',
                        'type' => 'integer',
                        'label' => __('Default mailbox'),
                        'description' => __('The mailbox a new reply starts from. Empty uses the first active mailbox of this brand.'),
                        'nullable' => true,
                        'min' => 1,
                    ],
                    [
                        'key' => 'ai.style_prompt',
                        'type' => 'text',
                        'label' => __('Style for AI drafts'),
                        'description' => __('How a suggested draft should sound: tone, length, how you greet and sign off. The draft lands in the form and is never sent on its own.'),
                        'nullable' => true,
                    ],
                ],
            ],
        ];
    }
}
