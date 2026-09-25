<?php

namespace Goldnead\StatamicInbox\Events;

use Goldnead\StatamicInbox\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

/** A new incoming message was stored by a fetch. Fired once per message. */
class InboxMessageReceived
{
    use Dispatchable;

    public function __construct(public Message $message) {}
}
