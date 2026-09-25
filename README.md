# Statamic Inbox

Your existing mailbox as conversations in the Statamic 6 Control Panel. The addon fetches INBOX and
Sent over IMAP, threads the mail into conversations, links each one to a LeadHub contact when the
address is known, and replies over the mailbox's own SMTP, so a reply comes from the real address and
lands in the normal Sent folder.

Commercial. **Under construction:** nothing here is released yet.

## Requirements

- PHP 8.2+, Laravel 12.40+ or 13, Statamic 6
- goldnead/statamic-brand-context 1.14+
- An IMAP/SMTP mailbox with an app password (Google Workspace, Migadu, manitu, All-Inkl, …).
  Microsoft 365 is not supported in v1: it needs OAuth.

Optional, each detected at runtime:

- goldnead/statamic-leadhub 2.13+: contact linking, an "Emails" panel on the contact, timeline entries
- goldnead/statamic-suppression 1.3+: refuses replies to hard-bounced or complaining addresses
- goldnead/statamic-email-templates: reply from a template
- goldnead/statamic-activity, goldnead/statamic-automations: activity feed and an "Email received" trigger

## Install

```bash
composer require goldnead/statamic-inbox
php artisan migrate
```

Schedule Laravel's scheduler if the site does not already run it: `inbox:fetch` is registered to
run every minute.

## Usage

1. Control Panel → Inbox → Mailboxes → add a mailbox. Pick the provider preset, enter the app
   password, press "Test connection".
2. The first fetch imports the last 90 days of INBOX and Sent (`INBOX_IMPORT_DAYS`).
3. Open a conversation, reply freely, from a template or from an AI draft. Nothing is sent
   without a click.

Permissions: `view inbox`, `reply inbox`, `manage inbox mailboxes`.

## Development

```bash
composer install
npm install && npm run build
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse
```
