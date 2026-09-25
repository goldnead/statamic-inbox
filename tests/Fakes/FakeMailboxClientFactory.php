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

    /** @var (callable(FakeMailboxClient): void)|null */
    protected $configure = null;

    public function client(Mailbox $mailbox): FakeMailboxClient
    {
        if (! isset($this->clients[$mailbox->getKey()])) {
            $client = new FakeMailboxClient;

            if ($this->configure !== null) {
                ($this->configure)($client);
            }

            $this->clients[$mailbox->getKey()] = $client;
        }

        return $this->clients[$mailbox->getKey()];
    }

    /**
     * Set up every client handed out from now on: the server of a mailbox
     * the test has not created yet (a CP form that creates it).
     *
     * Not by guessing the new mailbox's id: auto-increment counters survive
     * RefreshDatabase's rollback under InnoDB, so "the next id is 1" holds on
     * SQLite and only by luck of test order on MySQL.
     *
     * @param  callable(FakeMailboxClient): void  $configure
     */
    public function configureNewClients(callable $configure): static
    {
        $this->configure = $configure;

        return $this;
    }
}
