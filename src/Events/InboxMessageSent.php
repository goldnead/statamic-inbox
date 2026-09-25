<?php

namespace Goldnead\StatamicInbox\Events;

use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

/** A reply from the inbox left over the mailbox's SMTP. */
class InboxMessageSent
{
    use Dispatchable;

    public function __construct(public Message $message) {}
}
