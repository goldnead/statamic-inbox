<?php

namespace Goldnead\StatamicInbox\Contracts;

use Goldnead\StatamicInbox\Models\Mailbox;

interface MailboxClientFactory
{
    /**
     * A client connected (or connecting on first use) to this mailbox's IMAP
     * server. Implementations refuse private hosts before any connection.
     */
    public function for(Mailbox $mailbox): MailboxClient;
}
