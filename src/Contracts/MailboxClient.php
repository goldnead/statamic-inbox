<?php

namespace Goldnead\StatamicInbox\Contracts;

use DateTimeInterface;

/**
 * The three things the inbox needs from an IMAP server, and nothing else.
 *
 * Kept this narrow on purpose: the fetcher and the sender depend on it, the
 * ImapEngine adapter implements it, and the test suite replaces it with an
 * in-memory server fed with `.eml` files. Everything clever (threading,
 * parsing, dedupe) happens above this line, where it can be tested.
 */
interface MailboxClient
{
    /**
     * UIDs in the folder greater than `$afterUid`, ascending. On a first fetch
     * `$afterUid` is 0 and `$since` bounds the import by date (IMAP SINCE,
     * which compares dates, not times).
     *
     * @return list<int>
     */
    public function uidsAfter(string $folder, int $afterUid, ?DateTimeInterface $since = null): array;

    /** The complete RFC822 source of one message, without marking it read. */
    public function fetchRaw(string $folder, int $uid): string;

    /**
     * The header block of one message only (BODY.PEEK[HEADER]), without
     * marking it read. What `inbox:reclassify` needs, and nothing more.
     */
    public function fetchHeaders(string $folder, int $uid): string;

    /**
     * Store a message in a folder (the Sent copy of a reply).
     *
     * @param  array<int, string>  $flags
     */
    public function append(string $folder, string $raw, array $flags = ['\\Seen']): void;

    /**
     * The folder the server marks as Sent (RFC 6154 special-use `\Sent`),
     * falling back to the common names. Null when nothing fits.
     */
    public function detectSentFolder(): ?string;

    /**
     * The folder's UIDVALIDITY. When it changes, the server has renumbered
     * the folder and every stored UID cursor for it is meaningless.
     */
    public function uidValidity(string $folder): ?int;

    /**
     * The folder holding every mail (RFC 6154 `\All`, Gmail's "All Mail"),
     * or null. Where an archived mail still is.
     */
    public function detectAllMailFolder(): ?string;

    /** The UID of the message with this Message-ID in the folder (UID SEARCH), or null. */
    public function findUid(string $folder, string $messageId): ?int;

    /** Log in and list folders; throws with the server's reason when that fails. */
    public function check(): void;
}
