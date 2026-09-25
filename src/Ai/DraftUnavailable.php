<?php

namespace Goldnead\StatamicInbox\Ai;

use RuntimeException;

/**
 * No draft this time. `status` is the AI's HTTP status when it answered at
 * all, so the Control Panel can tell a missing key (401) from a busy
 * service (429, 529) without parsing the message.
 */
class DraftUnavailable extends RuntimeException
{
    public ?int $status = null;

    public function withStatus(?int $status): static
    {
        $this->status = $status;

        return $this;
    }
}
