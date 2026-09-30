# Statamic Inbox

Your existing mailbox as conversations in the Statamic 6 Control Panel. The addon fetches INBOX and
Sent over IMAP, threads the mail into conversations, links each one to a LeadHub contact when the
address is known, and replies over the mailbox's own SMTP, so a reply comes from the real address and
lands in the normal Sent folder.

Commercial. Version 0.1.0, experimental: exercised in a playground and against one real mailbox,
not yet running on a production site. Documentation: https://docs.adriangoldner.dev/inbox/

## Requirements

- PHP 8.2+, Laravel 12.40+ or 13, Statamic 6
- goldnead/statamic-brand-context 1.14+
- A running Laravel scheduler (`inbox:fetch` runs every minute) and a queue worker (replies are
  sent as queued jobs)
- An IMAP/SMTP mailbox with an app password (Google Workspace, Migadu, manitu, All-Inkl, …).
  Microsoft 365 is not supported in this release: it needs OAuth.

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

Run Laravel's scheduler if the site does not already (`* * * * * php artisan schedule:run`):
`inbox:fetch` is registered to run every minute, never overlapping itself, with one lock per
mailbox. Run a queue worker for the queue named in `INBOX_QUEUE` (default `default`); a reply
is stored first and then sent by a queued job, without automatic retries.

Publishing the config is optional:

```bash
php artisan vendor:publish --tag=inbox-config
```

## Configuration

| Variable | Default | What it does |
| --- | --- | --- |
| `INBOX_IMPORT_DAYS` | `90` | How far back the first fetch of a mailbox goes |
| `INBOX_ATTACHMENTS_DISK` | `local` | Private disk for attachments, served only through a permission-checked CP route |
| `INBOX_ALLOW_PRIVATE_HOSTS` | `false` | Allow IMAP/SMTP hosts on private or loopback addresses |
| `ANTHROPIC_API_KEY` | none | Enables the AI draft; without it a draft request answers that no key is configured |
| `ANTHROPIC_BASE_URL` | `https://api.anthropic.com` | API endpoint for the AI draft |
| `INBOX_AI_MODEL` | `claude-sonnet-5` | Model for the AI draft |
| `INBOX_QUEUE` | `default` | Queue the reply jobs go to |

Per brand, under Settings: a style prompt for AI drafts. Mailbox passwords are never settings:
they are stored encrypted on the mailbox row and never shown again.

## Usage

1. Control Panel → Tools → Postfach → Postfächer → add a mailbox. Pick the provider preset, enter
   the app password, press "Verbindung testen" (Test connection).
2. The first fetch imports the last 90 days of INBOX and Sent (`INBOX_IMPORT_DAYS`).
3. Open a conversation, reply freely, from a template or from an AI draft. Nothing is sent
   without a click. Signatures are set up per mailbox (tab "Signaturen") and picked in the reply
   form.

Mails the website itself sends through the mailbox (confirmations, invoices) are recognised as
system mails and never count as your reply; see `inbox.system_mail` in the config.

The Control Panel strings ship in German (`lang/de.json`); a few labels, among them the
"Postfach" nav entry, are German in every locale.

Limits of 0.1.0: no Microsoft 365 (no OAuth, app passwords only), no push or inbound webhooks
(new mail arrives with the next scheduled fetch), replies have no Cc and no formatting (plain text, sent with an escaped HTML copy), and a
mailbox cannot be deleted from the Control Panel, only switched off.

Permissions: `view inbox`, `reply inbox`, `manage inbox mailboxes`.

## For agents / CLI

`inbox:summary` answers "what lies in the inbox?" per mailbox, for an agent over SSH. It is read
only: it writes nothing (not even `unread`), dispatches no events and logs nothing. The numbers
come from the same query class as the Control Panel tabs, so `open` here is "Offen" there.

```bash
php artisan inbox:summary                      # one line per mailbox and a total
php artisan inbox:summary --json               # counts only
php artisan inbox:summary --json --details     # plus the first five conversations per number
php artisan inbox:summary --json --wartet=14 --brand=chor
```

Every mailbox of every brand unless `--brand=<handle|id>` narrows it; an unknown brand exits 1
with `{"error": "Unknown brand [x]."}`. `--wartet` (default 7) sets when a conversation in "Wartet"
counts as waiting too long.

```json
{
  "generated_at": "2026-09-30T08:00:00+02:00", "multi_brand": true, "brand": null, "waiting_days": 7,
  "mailboxes": [{
    "id": 1, "name": "Adrian", "email": "adrian@example.com", "brand": "default", "active": true,
    "last_fetched_at": "2026-09-30T07:59:00+02:00",
    "counts": {"new": 2, "open": 5, "open_unread": 1, "waiting": 3, "waiting_over_days": 1,
               "snoozed": 2, "snoozed_due_today": 1},
    "problems": {"has_problems": false, "last_error": null, "last_error_scope": null,
                 "folder_errors": {}, "failures_given_up": 0},
    "details": null
  }],
  "totals": {"new": 2, "open": 5, "open_unread": 1, "waiting": 3, "waiting_over_days": 1,
             "snoozed": 2, "snoozed_due_today": 1, "mailboxes_with_problems": 0}
}
```

With `--details`, `details` holds the same keys as `counts`, each a list of up to five
`{"id", "subject", "counterpart_email", "last_message_at", "age_days"}` — never a message body.
`snoozed_due_today` means the snooze ends before midnight in the app timezone.

## Security

- HTML mail is sanitised and rendered in a sandboxed frame without scripts; remote images load
  only on a click.
- IMAP and SMTP hosts that resolve to private, loopback or link-local addresses are refused, on
  save and before every connection.
- The app password is encrypted at rest, write-only in the form, and masked in error messages.

## Development

```bash
composer install
npm install && npm run build
vendor/bin/pest
vendor/bin/pint --test
vendor/bin/phpstan analyse
```
