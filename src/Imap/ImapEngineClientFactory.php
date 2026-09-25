<?php

namespace Goldnead\StatamicInbox\Imap;

use DirectoryTree\ImapEngine\Mailbox as ImapMailbox;
use Goldnead\StatamicInbox\Contracts\MailboxClient;
use Goldnead\StatamicInbox\Contracts\MailboxClientFactory;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Support\HostGuard;

class ImapEngineClientFactory implements MailboxClientFactory
{
    public function __construct(protected HostGuard $guard) {}

    public function for(Mailbox $mailbox): MailboxClient
    {
        // Before anything is built: a private host never gets a socket.
        $this->guard->check($mailbox->imap_host);

        return new ImapEngineClient(new ImapMailbox([
            'host' => $mailbox->imap_host,
            'port' => $mailbox->imap_port,
            'encryption' => $this->encryption($mailbox->imap_encryption),
            'username' => $mailbox->username,
            'password' => $mailbox->password,
            'timeout' => 30,
            'validate_cert' => true,
        ]), $this->guard, $mailbox->imap_host);
    }

    /** ssl and tls mean TLS from the first byte (port 993); starttls upgrades; none is plain. */
    protected function encryption(string $value): string
    {
        return match (strtolower($value)) {
            'ssl', 'tls' => 'ssl',
            'starttls' => 'starttls',
            default => '',
        };
    }
}
