<?php

namespace Goldnead\StatamicInbox\Tests\Fakes;

use Goldnead\StatamicInbox\Contracts\MailboxClient;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Models\Mailbox;

/**
 * Hands out one {@see FakeMailboxClient} per mailbox, the same instance every
 * time, so a test can deliver into it before a fetch and read what was
 * appended after a send.
 */
class FakeMailboxClientFactory implements MailboxClientFactory
{
    /** @var array<int|string, FakeMailboxClient> */
    public array $clients = [];

    public function for(Mailbox $mailbox): MailboxClient
    {
        return $this->client($mailbox);
    }

    public function client(Mailbox $mailbox): FakeMailboxClient
    {
        return $this->clients[$mailbox->getKey()] ??= new FakeMailboxClient;
    }
}
