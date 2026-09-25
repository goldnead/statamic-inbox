<?php

namespace Goldnead\StatamicInbox\Integrations;

use Goldnead\StatamicInbox\Exceptions\ReplyRefused;
use Throwable;

/**
 * Asks goldnead/statamic-suppression whether a direct reply may go out.
 *
 * Only a hard bounce, a complaint or an address known to be invalid blocks
 * (decided 25.09.2026). Someone who writes to you has not objected to an
 * answer, so a manual block or a soft-bounce threshold does not stop one.
 *
 * Why not `SuppressionGate::isSuppressed()`: the gate answers yes/no for any
 * active entry, whatever its reason, and a reply must not be blocked by a
 * `manual` block or a `soft_bounce_threshold`. So this asks the Suppression
 * model directly, with the same scopes the gate uses (`visibleTo`, `active`,
 * `forEmail`), and filters on the reason constants of
 * `goldnead/statamic-suppression/src/Reasons.php` (HARD_BOUNCE, COMPLAINT,
 * INVALID_EMAIL). If Reasons ever renames them, BLOCKING_REASONS follows.
 *
 * Fail-closed, like the suppression gate itself: when the list cannot be
 * read, nothing is sent. Without the package installed there is no list and
 * nothing to check.
 */
class SuppressionCheck
{
    public const MODEL = 'Goldnead\\Suppression\\Models\\Suppression';

    public const NORMALIZER = 'Goldnead\\Suppression\\Support\\EmailNormalizer';

    public const BLOCKING_REASONS = ['hard_bounce', 'complaint', 'invalid_email'];

    public function available(): bool
    {
        return class_exists(self::MODEL) && class_exists(self::NORMALIZER);
    }

    /** @throws ReplyRefused */
    public function assertMayReply(string $email): void
    {
        if (! $this->available()) {
            return;
        }

        try {
            $normalized = (self::NORMALIZER)::normalize($email);

            if ($normalized === null) {
                throw ReplyRefused::noRecipient();
            }

            $reason = (self::MODEL)::query()
                ->visibleTo()
                ->active()
                ->forEmail($normalized)
                ->whereIn('reason', self::BLOCKING_REASONS)
                ->value('reason');
        } catch (ReplyRefused $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ReplyRefused(ReplyRefused::uncheckable()->getMessage(), 0, $e);
        }

        if ($reason !== null) {
            throw ReplyRefused::suppressed($email, (string) $reason);
        }
    }
}
