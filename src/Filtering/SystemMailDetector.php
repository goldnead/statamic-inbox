<?php

namespace Goldnead\StatamicInbox\Filtering;

/**
 * Did the website send this outgoing mail itself, through the same mailbox?
 * Order and booking confirmations, invoices, access mails: a system mail.
 *
 * A system mail makes no conversation relevant, does not set it to
 * "waiting" and does not make its recipient a known correspondent. Decided
 * on what is stored with every message (filter headers, Message-ID,
 * subject), so `inbox:reclassify` comes to the same answer later.
 *
 * In this order:
 *  1. an automation header: `Auto-Submitted` other than `no`,
 *     `X-Auto-Response-Suppress`, `X-Suite-*` (extendable via
 *     `inbox.system_mail.headers`);
 *  2. the Message-ID: our own replies (`inbox.…`) and Gmail's webmail are
 *     never system mail; a Symfony/Laravel id (32 hex characters) on a mail
 *     that answers nothing is;
 *  3. the subject starts with one of `inbox.system_mail.subjects`.
 *
 * As of 0.2.2 none of the suite's addons sets a header of its own; step 2
 * is what recognises their mail.
 */
class SystemMailDetector
{
    /** Header names that mark automatic mail. A trailing * matches a prefix. */
    public const HEADERS = ['x-auto-response-suppress', 'x-suite-*'];

    /** @param  array<string, mixed>  $headers  stored filter headers (MessageParser) */
    public function isSystem(array $headers, string $messageId, string $subject, ?string $inReplyTo): bool
    {
        $autoSubmitted = $headers['auto_submitted'] ?? null;

        if ($autoSubmitted !== null && $autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        if ($this->hasAutomationHeader(array_map('strtolower', (array) ($headers['header_names'] ?? [])))) {
            return true;
        }

        $id = strtolower(trim($messageId, " \t<>"));

        // Sent from this inbox, or typed in Gmail: a person wrote it.
        if (str_starts_with($id, 'inbox.') || str_ends_with($id, '@mail.gmail.com')) {
            return false;
        }

        // Symfony Mailer (and so Laravel) makes ids of 32 hex characters. A
        // mail program answering someone would carry In-Reply-To.
        if (preg_match('/^[0-9a-f]{32}@/', $id) === 1 && ($inReplyTo === null || trim($inReplyTo) === '')) {
            return true;
        }

        return $this->subjectMatches($subject);
    }

    protected function subjectMatches(string $subject): bool
    {
        $subject = mb_strtolower(trim($subject));

        foreach ((array) config('inbox.system_mail.subjects', []) as $pattern) {
            $pattern = mb_strtolower(trim((string) $pattern));

            if ($pattern !== '' && str_starts_with($subject, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $names */
    protected function hasAutomationHeader(array $names): bool
    {
        $patterns = array_map('strtolower', [...self::HEADERS, ...(array) config('inbox.system_mail.headers', [])]);

        foreach ($names as $name) {
            foreach ($patterns as $pattern) {
                if (str_ends_with($pattern, '*') ? str_starts_with($name, rtrim($pattern, '*')) : $name === $pattern) {
                    return true;
                }
            }
        }

        return false;
    }
}
