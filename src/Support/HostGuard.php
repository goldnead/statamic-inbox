<?php

namespace Goldnead\StatamicInbox\Support;

/**
 * Keeps IMAP and SMTP connections off the server's own network.
 *
 * A mailbox's hosts are typed in by a CP user and then connected to by the
 * server, so without this the mailbox form is a way to reach loopback, the
 * cloud metadata endpoint (169.254.169.254) or anything else on the private
 * network. Every address the host resolves to has to be public.
 *
 * Checked when a mailbox is saved and again right before every connection:
 * a name can resolve to a public address at save time and to 127.0.0.1 later.
 *
 * `inbox.allow_private_hosts` switches the check off for a mail server on the
 * same private network.
 *
 * ponytail: copied from statamic-automations' Support\HostGuard (address
 * ranges and the IPv4-mapped handling unchanged, the URL/curl-pinning part
 * dropped because IMAP and SMTP are not HTTP). Two addons now carry it; when
 * a third needs it, move it into goldnead/statamic-brand-context and delete
 * both copies.
 */
class HostGuard
{
    /**
     * Ranges no connection may reach. IPv4-mapped and -compatible IPv6
     * addresses are checked as the IPv4 address they carry.
     */
    protected const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var (callable(string): array<int, string>)|null */
    protected $resolver = null;

    /** Swap the DNS lookup, e.g. in tests. Null restores the real one. */
    public function resolveUsing(?callable $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * Throw unless `$host` may be connected to.
     *
     * @throws UnsafeHostException
     */
    public function check(string $host): void
    {
        $host = trim($host);

        if ($host === '') {
            throw new UnsafeHostException(__('No host given.'));
        }

        if (config('inbox.allow_private_hosts', false)) {
            return;
        }

        $literal = trim($host, '[]');
        $ips = filter_var($literal, FILTER_VALIDATE_IP) ? [$literal] : $this->resolve($host);

        if ($ips === []) {
            throw new UnsafeHostException(__("The host ':host' could not be resolved.", ['host' => $host]));
        }

        foreach ($ips as $ip) {
            if ($this->isBlocked($ip)) {
                throw new UnsafeHostException(__("The host ':host' points to a private or reserved address (:ip).", ['host' => $host, 'ip' => $ip]));
            }
        }
    }

    /** @return array<int, string> */
    protected function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(($this->resolver)($host));
        }

        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    protected function isBlocked(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        // ::ffff:a.b.c.d and ::a.b.c.d reach the IPv4 address they carry.
        if (strlen($packed) === 16 && (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") || str_starts_with($packed, str_repeat("\0", 12)))
            && ! in_array($packed, [str_repeat("\0", 16), str_repeat("\0", 15)."\1"], true)) {
            $packed = substr($packed, 12);
        }

        foreach (self::BLOCKED as $range) {
            [$network, $bits] = explode('/', $range);
            $net = (string) inet_pton($network);

            if (strlen($net) === strlen($packed) && $this->inRange($packed, $net, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    protected function inRange(string $ip, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if (substr($ip, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ip[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
