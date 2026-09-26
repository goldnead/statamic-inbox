# Changelog

## Unreleased

### Changed

- The Migadu preset sends over port 587 with STARTTLS instead of 465. Many hosts block outbound
  465, and the connection test then times out. Sites that published `config/inbox.php` keep their
  own value; existing mailboxes keep their saved port.

## 0.2.1 — 2026-09-25

The filter could hide or delete real leads. Fixed, with a way to get back what 0.2.0 skipped.

### Fixed

- **Hiding a sender or domain deletes first contacts only.** Conversations you answered, with a
  LeadHub contact, or taken over stay, your own sent mails in them included. Hiding works only from
  a conversation in "Neu", and the dialog says beforehand how many conversations will be deleted.
  A contact, someone you have written to before, or a reply in an existing conversation now also
  gets through a hidden sender or domain.
- **Sent is read before INBOX.** A reply that arrived in INBOX in the same fetch as your mail found
  no thread yet and could be taken for bulk mail (an out-of-office answer, a ticket system).
  Someone you have written to before is now an exception from the bulk filter as well.
- **Contact forms and booking tools.** A mail from `noreply@` with a person in `Reply-To` is that
  person: they become the other side of the conversation, the exceptions apply to them, and the
  no-reply rule does not fire.
- **Skip records keep less.** The Message-ID only as a SHA-256 hash, the sender only for hidden
  senders (where removing the rule needs it). A migration clears the sender of the others.
- Removing a hidden sender needs `reply inbox`, like hiding it; before, it needed
  `manage inbox mailboxes`.
- `inbox:reclassify` takes a conversation out of "Neu" once it has become relevant, applies
  relevance even when headers cannot be read (archived mail), and reads headers stored by 0.2.0
  again because they lack `Reply-To`.
- A conversation that starts with your reply is titled without `Re:`.

### Added

- `inbox:reclassify --reconsider-skipped [--dry-run]` looks at every skip record again (except
  hidden senders): headers by PEEK at the recorded folder and UID, or, when the UID is gone, a
  search by Message-ID in INBOX and All Mail. What no longer counts as bulk mail is imported; the
  records left are hashed.

### Upgrading

- `php artisan migrate`, then `php artisan inbox:reclassify --reconsider-skipped --dry-run`, then
  without `--dry-run`, then `php artisan inbox:reclassify --dry-run` to check nothing is left.

## 0.2.0 — 2026-09-25

Only relevant mail. The inbox is for conversations with leads and customers; newsletters and
invoices stay in your mail program.

### Added

- **Bulk mail is not taken over.** A mail with `List-Unsubscribe`, `List-Id` or `List-Post`,
  `Precedence: bulk/list/junk`, `Auto-Submitted` (other than `no`), typical mailing-service headers
  (`Feedback-ID`, `X-Campaign`, `X-Mailchimp-*`, `X-SES-Outgoing`, `X-MC-User` and more, extendable
  via `inbox.filter.bulk_headers`), a no-reply sender or an empty `Return-Path` (bounces) is not
  stored at all. Only a skip record is kept (Message-ID, folder, UID, reason), so it is not fetched
  twice. A LeadHub contact and a reply in an existing conversation always come through. Sent mail
  to more than ten people or by Bcc list counts as a circular and is skipped too. Switch per
  mailbox: "Massenmails überspringen", on by default.
- **First contacts in their own tab "Neu".** A conversation is relevant when the other side is a
  LeadHub contact, when you answered or started it, or when you have written to that address from
  this mailbox before. Everything else is filed as `new`: shown only in "Neu", with its own count,
  not in the menu badge. Actions there: "Übernehmen", "Kontakt anlegen", "Absender ausblenden",
  "Domain ausblenden". A reply takes a conversation out of "Neu" by itself.
- **Hidden senders and domains.** Hiding deletes the existing conversations in Statamic
  (attachments included) and keeps future mail out; the mails stay in Gmail or your mailbox.
  Freemail domains (gmail.com, gmx.de, web.de and others) can only be hidden sender by sender. The
  mailbox page lists the rules with "Entfernen".
- **Own addresses.** Aliases per mailbox; mail between your own addresses makes no conversation.
- `inbox:reclassify {--mailbox=} {--dry-run}` applies the filter to mail imported before: reads the
  headers again over IMAP (headers only, PEEK, nothing is marked read), deletes bulk and
  to-yourself conversations with their files, files unknown people under "Neu". Always run
  `--dry-run` first.
- The mailbox page shows the bulk mail skipped in the last 30 days.
- New messages store the headers the filter decides on (`filter_headers`), so a rule change can be
  applied again without asking the server.

### Changed

- A first contact from someone unknown now arrives as `new`, not `open`.

### Fixed

- The mailbox form now saves the sender name ("Absendername"); since 0.1.0 it was shown but not
  sent.

### Upgrading

- Run `php artisan migrate`, then `php artisan inbox:reclassify --dry-run` and, when the numbers
  look right, `php artisan inbox:reclassify`.

## 0.1.1 — 2026-09-25

### Fixed

- The addon list no longer calls the addon "Postfach-Einstellungen". The settings entry gets its name from `settingsTitle()` (brand-context 1.15), not from a global translation of the addon name "Inbox". With an older brand-context the entry reads "Inbox".

## 0.1.0 — 2026-09-25

First release.

- Control Panel: the inbox as core's Listing (tabs Offen, Wartet, Erledigt, Geschlummert, search,
  mailbox filter, unread dot, failed sends), fetch problems per mailbox above it, an empty state
  that leads to connecting a mailbox.
- Conversation screen: thread with older messages and quoted text folded, HTML in a sandboxed
  frame without scripts, remote images only on click, inline images and attachments through the
  permission-checked route, LeadHub contact card or "Kontakt anlegen", status and snooze, reply
  form with template and AI draft that only fill the text.
- Mailbox screens: list, create and edit with provider presets, write-only password that is asked
  for again when server, port or login change, connection test before or after saving.
- German translations (`lang/de.json`), `scripts/setup-playground.sh` with seed data from the
  IMAP fake.
- Package skeleton: service provider, per-brand settings through brand-context, permissions
  `view inbox`, `reply inbox`, `manage inbox mailboxes`.
- Fetching over IMAP (directorytree/imapengine): INBOX and Sent, UID cursor per folder, first run
  from `import_since` (90 days), dedupe by Message-ID per mailbox, `inbox:fetch` every minute with
  a lock per mailbox, each mailbox inside its brand.
- Threading by In-Reply-To, References, then counterpart plus normalised subject within 30 days.
- Parsing with zbateson/mail-mime-parser, HTML through symfony/html-sanitizer with remote images
  held back (`data-inbox-src`), quoted replies stripped (own marker, then email-reply-parser).
- Replies over the mailbox's own SMTP with Message-ID, In-Reply-To and References, APPEND to Sent
  except on Gmail, queued, failures kept on the message (`send_error`).
- Suppression: hard bounce, complaint and invalid address block a reply; fail-closed.
- LeadHub: contact linking (never creating), timeline entries deduped by `inbox:{message_id}`.
- AI draft (Claude Messages API, `ANTHROPIC_API_KEY`), templates from email-templates, activity
  producer and an automations trigger; all optional and detected at runtime.
- Host guard against private, loopback and link-local IMAP/SMTP hosts.
- CP JSON endpoints and Inertia pages (`inbox::…`); the Vue screens follow.
- Hardening after review: a message that cannot be stored no longer freezes a mailbox (ledger
  `inbox_fetch_failures`, three attempts, then given up visibly); overlong fields are cut, overlong
  Message-IDs indexed by hash; errors per folder (`folder_errors`) apart from a broken mailbox;
  UIDVALIDITY per folder; future Date headers clamped; `srcset` and `<source>` blocked as remote
  images; `cid:` images mapped to the attachment route; APPEND failures kept as `filed_error`;
  password required again when host, port or login change (also for the connection test);
  Gmail and Sent folder re-detected on a host change; contact links checked against LeadHub.
- Nav item "Postfach" with the unread count, "E-Mails" panel on the LeadHub contact, and
  "Kontakt anlegen" from a conversation. The unused `default_mailbox` setting is gone.
- AI drafts default to `claude-sonnet-5`.
