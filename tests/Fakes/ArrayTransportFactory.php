<?php

namespace Goldnead\StatamicInbox\Tests\Fakes;

use Goldnead\StatamicInbox\Contracts\TransportFactory;
use Goldnead\StatamicInbox\Models\Mailbox;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Stands in for the mailbox's SMTP. Every mailbox gets the same in-memory
 * transport, so a test reads the Symfony messages that would have gone out,
 * headers and all, and knows which mailbox asked for them.
 */
class ArrayTransportFactory implements TransportFactory
{
    public ArrayTransport $transport;

    /** @var list<int|string> mailbox keys, in the order they asked */
    public array $requestedFor = [];

    public function __construct()
    {
        $this->transport = new ArrayTransport;
    }

    public function for(Mailbox $mailbox): TransportInterface
    {
        $this->requestedFor[] = $mailbox->getKey();

        return $this->transport;
    }
}
