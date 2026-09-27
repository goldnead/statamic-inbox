<?php

namespace Goldnead\StatamicInbox\Filtering;

/**
 * Did the website send this outgoing mail itself, through the same mailbox?
 * Order and booking confirmations, invoices, access mails: a system mail.
 *
 * A system mail makes no conversation relevant, does not set it to
 * "waiting" and does not make its recipient a known correspondent; alone it
 * opens no conversation, and `inbox:reclassify` deletes conversations made
 * only of such mails. A mistake here loses your own mail, so everything but
 * an explicit automation header needs two signals, and a sign of a person
 * wins over any of them.
 *
 * Decided on what is stored with every message (filter headers,
 * Message-ID, subject, the id it answers), so `inbox:reclassify` comes to
 * the same answer later:
 *
 *  1. `Auto-Submitted` other than `no`, `X-Auto-Response-Suppress` or
 *     `X-Suite-*` (extendable via `inbox.system_mail.headers`): system.
 *  2. A person wrote it, never system: our own replies (`inbox.…`), Gmail's
 *     webmail, a mail program in `User-Agent`/`X-Mailer` (Roundcube,
 *     Thunderbird, Apple Mail, Outlook …), or a mail that answers another
 *     (In-Reply-To or References).
 *  3. The subject starts with one of `inbox.system_mail.subjects` (the
 *     suite's own subjects, both forms of address): system.
 *  4. A Symfony/Laravel Message-ID (32 hex characters) together with a
 *     sender name from `inbox.system_mail.senders`: system. The id alone
 *     never is: Roundcube builds the same shape (md5(uniqid())).
 *
 * As of 0.2.2 none of the suite's addons sets a header of its own; the
 * subjects are what recognises their mail.
 */
class SystemMailDetector
{
    /** Header names that mark automatic mail. A trailing * matches a prefix. */
    public const HEADERS = ['x-auto-response-suppress', 'x-suite-*'];

    /** Mail programs a person writes with, as they name themselves in User-Agent or X-Mailer. */
    public const MAIL_PROGRAMS = [
        'roundcube', 'thunderbird', 'mozilla', 'apple mail', 'iphone mail', 'ipad mail', 'outlook', 'microsoft',
        'k-9', 'k9', 'fairemail', 'mutt', 'evolution', 'claws', 'sogo', 'horde', 'rainloop', 'snappymail',
        'spark', 'airmail', 'mailmate', 'postbox', 'em client', 'canary', 'samsung', 'bluemail', 'the bat',
        'mailbird', 'kmail', 'geary', 'squirrelmail', 'open-xchange', 'zimbra', 'gmail', 'web.de', 'gmx',
    ];

    /** @param  array<string, mixed>  $headers  stored filter headers (MessageParser) */
    public function isSystem(array $headers, string $messageId, string $subject, ?string $answers): bool
    {
        $autoSubmitted = $headers['auto_submitted'] ?? null;

        if ($autoSubmitted !== null && $autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        if ($this->hasAutomationHeader(array_map('strtolower', (array) ($headers['header_names'] ?? [])))) {
            return true;
        }

        if ($this->writtenByAPerson($headers, $messageId, $answers)) {
            return false;
        }

        if ($this->subjectMatches($subject)) {
            return true;
        }

        return preg_match('/^[0-9a-f]{32}@/', strtolower(trim($messageId, " \t<>"))) === 1
            && $this->senderMatches((string) ($headers['from_name'] ?? ''));
    }

    /** @param  array<string, mixed>  $headers */
    protected function writtenByAPerson(array $headers, string $messageId, ?string $answers): bool
    {
        $id = strtolower(trim($messageId, " \t<>"));

        // Sent from this inbox, or typed in Gmail.
        if (str_starts_with($id, 'inbox.') || str_ends_with($id, '@mail.gmail.com')) {
            return true;
        }

        // The website's mails answer nothing; a mail that answers is a reply.
        if ($answers !== null && trim($answers) !== '') {
            return true;
        }

        $mailer = strtolower((string) ($headers['mailer'] ?? ''));

        foreach (self::MAIL_PROGRAMS as $program) {
            if ($mailer !== '' && str_contains($mailer, $program)) {
                return true;
            }
        }

        return false;
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

    protected function senderMatches(string $name): bool
    {
        $name = mb_strtolower(trim($name));

        if ($name === '') {
            return false;
        }

        foreach ((array) config('inbox.system_mail.senders', []) as $sender) {
            if (mb_strtolower(trim((string) $sender)) === $name) {
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
