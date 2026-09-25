<?php

namespace Goldnead\StatamicInbox\Contracts;

use Goldnead\StatamicInbox\Models\Mailbox;
use Symfony\Component\Mailer\Transport\TransportInterface;

interface TransportFactory
{
    /**
     * The SMTP transport of this mailbox. Replies go out over the mailbox's
     * own server so they come from the real address and SPF/DKIM match.
     */
    public function for(Mailbox $mailbox): TransportInterface;
}
