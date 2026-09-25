<?php

namespace Goldnead\StatamicInbox\Filtering;

/**
 * Is this bulk mail? Decided on the stored filter headers alone (see
 * MessageParser::filterHeaders()), so the same answer comes out at fetch
 * time and when `inbox:reclassify` applies the rules again later.
 *
 * The exceptions (a LeadHub contact, a reply in an existing conversation)
 * are not decided here; they need the database and belong to the caller.
 */
class BulkDetector
{
    /** Headers typical of mailing services. A trailing * matches a prefix. */
    public const BULK_HEADERS = [
        'feedback-id', 'x-campaign', 'x-campaign-id', 'x-campaignid', 'x-mailchimp-*', 'x-mc-user',
        'x-ses-outgoing', 'x-sg-eid', 'x-mandrill-user', 'x-mailgun-*', 'x-csa-complaints',
        'x-newsletter', 'x-listmember', 'x-sendinblue-*', 'x-mailjet-campaign', 'x-brevo-*',
    ];

    public const NOREPLY_PREFIXES = ['noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster'];

    /** More recipients than this on an outgoing mail make it a circular. */
    public const MAX_PERSONAL_RECIPIENTS = 10;

    /**
     * Why an incoming mail is bulk mail, or null.
     *
     * @param  array<string, mixed>  $headers
     */
    public function reason(array $headers): ?string
    {
        $names = array_map('strtolower', (array) ($headers['header_names'] ?? []));

        if (array_intersect(['list-unsubscribe', 'list-id', 'list-post'], $names) !== []) {
            return 'list_header';
        }

        if (in_array($headers['precedence'] ?? null, ['bulk', 'list', 'junk'], true)) {
            return 'precedence';
        }

        $autoSubmitted = $headers['auto_submitted'] ?? null;
        if ($autoSubmitted !== null && $autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return 'auto_submitted';
        }

        if ($this->hasBulkHeader($names)) {
            return 'bulk_sender_header';
        }

        // A no-reply sender with a real person in Reply-To (contact form,
        // booking tool) is that person writing, not a machine.
        if (self::isNoReply((string) ($headers['from'] ?? '')) && self::personalReplyTo($headers) === null) {
            return 'noreply_sender';
        }

        if (in_array(str_replace(' ', '', (string) ($headers['return_path'] ?? 'x')), ['<>', ''], true)) {
            return 'bounce';
        }

        return null;
    }

    /**
     * Why an outgoing mail is a circular, or null. A personal mail from Sent
     * is never bulk; one to more than ten people, or sent by Bcc list, is.
     *
     * @param  array<string, mixed>  $headers
     * @param  list<string>  $own
     */
    public function outgoingReason(array $headers, array $own): ?string
    {
        $recipients = (array) ($headers['recipients'] ?? []);
        $others = fn (array $list) => array_values(array_diff(array_map('strtolower', $list), $own));

        $to = $others((array) ($recipients['to'] ?? []));
        $cc = $others((array) ($recipients['cc'] ?? []));
        $bcc = $others((array) ($recipients['bcc'] ?? []));

        if (count(array_unique([...$to, ...$cc, ...$bcc])) > self::MAX_PERSONAL_RECIPIENTS) {
            return 'mass_outgoing';
        }

        // Nobody in To or Cc but yourself (or "undisclosed recipients"),
        // everyone in Bcc: a list sent the discreet way.
        if ($to === [] && $cc === [] && ($bcc !== [] || ($headers['undisclosed'] ?? false))) {
            return 'mass_outgoing';
        }

        return null;
    }

    public static function isNoReply(string $email): bool
    {
        $local = strtolower(strstr($email, '@', true) ?: '');

        foreach (self::NOREPLY_PREFIXES as $prefix) {
            if ($local !== '' && str_starts_with($local, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first Reply-To address that is a person, not another no-reply
     * address and none of ours. That person is the other side of the mail.
     *
     * @param  array<string, mixed>  $headers
     * @param  list<string>  $own
     */
    public static function personalReplyTo(array $headers, array $own = []): ?string
    {
        foreach ((array) ($headers['reply_to'] ?? []) as $address) {
            $address = strtolower(trim((string) $address));

            if ($address !== '' && ! self::isNoReply($address) && ! in_array($address, $own, true)) {
                return $address;
            }
        }

        return null;
    }

    /** @param  list<string>  $names */
    protected function hasBulkHeader(array $names): bool
    {
        $patterns = array_map('strtolower', [
            ...self::BULK_HEADERS,
            ...(array) config('inbox.filter.bulk_headers', []),
        ]);

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
