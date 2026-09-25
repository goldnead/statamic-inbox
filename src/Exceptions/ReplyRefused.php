<?php

namespace Goldnead\StatamicInbox\Exceptions;

use RuntimeException;

/**
 * A reply was not sent because the address may not be written to, or because
 * that could not be checked. Nothing left the building.
 */
class ReplyRefused extends RuntimeException
{
    public static function suppressed(string $email, string $reason): self
    {
        return new self(__('Not sent: :email is on the suppression list (:reason).', ['email' => $email, 'reason' => $reason]));
    }

    public static function uncheckable(): self
    {
        return new self(__('Not sent: the suppression list could not be checked.'));
    }

    public static function noRecipient(): self
    {
        return new self(__('Not sent: the conversation has no address to reply to.'));
    }
}
