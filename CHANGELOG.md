# Changelog

## Unreleased

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
