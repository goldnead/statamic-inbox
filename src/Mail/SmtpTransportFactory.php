<?php

namespace Goldnead\StatamicInbox\Mail;

use Goldnead\StatamicInbox\Contracts\TransportFactory;
use Goldnead\StatamicInbox\Models\Mailbox;
use Goldnead\StatamicInbox\Support\HostGuard;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The mailbox's own SMTP server as a Symfony transport. Thin by design; the
 * header work happens in the sender, where it is tested.
 */
class SmtpTransportFactory implements TransportFactory
{
    public function __construct(protected HostGuard $guard) {}

    public function for(Mailbox $mailbox): TransportInterface
    {
        $this->guard->check($mailbox->smtp_host);

        $encryption = strtolower($mailbox->smtp_encryption);

        // ssl: TLS from the first byte (465). tls/starttls: plain connect,
        // then STARTTLS, which EsmtpTransport requires when asked to. none:
        // plain, for a relay on the same network only.
        $transport = new EsmtpTransport($mailbox->smtp_host, $mailbox->smtp_port, $encryption === 'ssl');

        if (in_array($encryption, ['tls', 'starttls'], true)) {
            $transport->setRequireTls(true);
        }

        if ($encryption === 'none') {
            $transport->setAutoTls(false);
        }

        $transport->setUsername($mailbox->username);
        $transport->setPassword($mailbox->password);
        $transport->setLocalDomain($mailbox->domain());

        return $transport;
    }
}
