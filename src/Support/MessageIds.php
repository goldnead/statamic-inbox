<?php

namespace Goldnead\StatamicInbox\Support;

/**
 * Message-IDs as the database stores them.
 *
 * The id columns are indexed and 191 characters wide (InnoDB key length
 * under utf8mb4). A longer id, which RFC 5322 allows, is stored as a hash;
 * every lookup goes through {@see key()} so a reference to it still matches.
 */
class MessageIds
{
    public const MAX = 191;

    public static function key(string $id): string
    {
        return strlen($id) <= self::MAX ? $id : 'sha256:'.hash('sha256', $id);
    }

    public static function isHashed(string $id): bool
    {
        return strlen($id) > self::MAX;
    }

    /** A column value cut to its width, by characters, never mid-character. */
    public static function fit(?string $value, int $length = 255): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
