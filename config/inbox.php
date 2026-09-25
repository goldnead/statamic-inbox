<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fetching
    |--------------------------------------------------------------------------
    |
    | `inbox:fetch` runs every minute from the scheduler, one mailbox at a time
    | and never overlapping itself per mailbox. A mailbox fetched for the first
    | time imports this many days back; after that only UIDs above the last
    | one seen are fetched.
    |
    */

    'fetch' => [
        'import_days' => (int) env('INBOX_IMPORT_DAYS', 90),

        // Same counterpart, same normalised subject, within this many days:
        // the third and last threading rule before a new conversation opens.
        'subject_match_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Attachments land on a private disk. They are served only through a CP
    | route that checks the `view inbox` permission, never from a public URL.
    |
    */

    'attachments' => [
        'disk' => env('INBOX_ATTACHMENTS_DISK', 'local'),
        'path' => 'inbox/attachments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Hosts
    |--------------------------------------------------------------------------
    |
    | IMAP and SMTP hosts are refused when they resolve to a private or
    | loopback address, so a mailbox form cannot be used to probe the network
    | the site runs in. Switch this on only for a mail server on the same
    | private network.
    |
    */

    'allow_private_hosts' => (bool) env('INBOX_ALLOW_PRIVATE_HOSTS', false),

    /*
    |--------------------------------------------------------------------------
    | AI draft
    |--------------------------------------------------------------------------
    |
    | The same variable statamic-automations reads. A draft only ever fills the
    | reply form; nothing is sent without a click.
    |
    */

    'ai' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('INBOX_AI_MODEL', 'claude-sonnet-4-5'),
        'style_prompt' => null,
    ],

    // The mailbox the reply form preselects for this brand; null means the
    // first active one.
    'default_mailbox' => null,

    'queue' => env('INBOX_QUEUE', 'default'),

];
