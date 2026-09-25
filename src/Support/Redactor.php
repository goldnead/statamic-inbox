<?php

namespace Goldnead\StatamicInbox\Support;

use Goldnead\StatamicInbox\Models\Mailbox;
use Throwable;

/**
 * Takes a mailbox's password out of anything that is about to be shown,
 * stored or logged. IMAP and SMTP libraries echo the failed command in their
 * exceptions, and a LOGIN command carries the password in clear.
 */
class Redactor
{
    public const MASK = '********';

    public static function mask(string $text, Mailbox $mailbox): string
    {
        foreach ($mailbox->secrets() as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        return $text;
    }

    /** The exception's message, and its previous ones, with the secrets masked. */
    public static function message(Throwable $e, Mailbox $mailbox): string
    {
        $messages = [];

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return self::mask(implode(' — ', array_unique(array_filter($messages))), $mailbox);
    }
}
