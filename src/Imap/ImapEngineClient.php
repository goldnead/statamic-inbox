<?php

namespace Goldnead\StatamicInbox\Imap;

use DateTimeInterface;
use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox as ImapMailbox;
use Goldnead\StatamicInbox\Contracts\MailboxClient;
use Goldnead\StatamicInbox\Support\HostGuard;
use RuntimeException;

/**
 * {@see MailboxClient} on top of directorytree/imapengine.
 *
 * Deliberately thin: it translates the three calls the inbox needs into
 * ImapEngine calls and does nothing else. It cannot be tested here without a
 * real server; tests/Feature/ImapIntegrationTest.php runs it against one on
 * request.
 */
class ImapEngineClient implements MailboxClient
{
    /** Names a server without RFC 6154 special-use flags is likely to use. */
    public const SENT_NAMES = [
        'Sent', 'INBOX.Sent', 'Sent Items', 'Sent Messages', 'Gesendet', 'Gesendete Objekte',
        'Gesendete Elemente', 'INBOX.Gesendet', '[Gmail]/Sent Mail', '[Gmail]/Gesendet',
    ];

    public function __construct(
        protected ImapMailbox $imap,
        protected HostGuard $guard,
        protected string $host,
    ) {}

    public function uidsAfter(string $folder, int $afterUid, ?DateTimeInterface $since = null): array
    {
        $query = $this->folder($folder)->messages();
        $query->uid($afterUid + 1, INF);

        if ($since !== null) {
            $query->since($since);
        }

        $response = $this->connection()->search([$query->toImap()]);

        $uids = [];

        foreach ($response->tokensAfter(2) as $token) {
            $uid = (int) $token->value;

            // "n:*" always matches the highest UID, even when it is below n.
            if ($uid > $afterUid) {
                $uids[] = $uid;
            }
        }

        sort($uids);

        return $uids;
    }

    public function fetchRaw(string $folder, int $uid): string
    {
        $message = $this->folder($folder)->messages()
            ->withHeaders()
            ->withBody()
            ->find($uid);

        if ($message === null) {
            throw new RuntimeException("No message with UID {$uid} in {$folder}.");
        }

        return (string) $message;
    }

    public function fetchHeaders(string $folder, int $uid): string
    {
        // withHeaders() alone: BODY.PEEK[HEADER], no body, \Seen untouched.
        $message = $this->folder($folder)->messages()->withHeaders()->find($uid);

        if ($message === null) {
            throw new RuntimeException("No message with UID {$uid} in {$folder}.");
        }

        // Fetched without a body, the message's string form is its header block.
        return rtrim((string) preg_split('/\r?\n\r?\n/', (string) $message, 2)[0])."\r\n\r\n";
    }

    public function append(string $folder, string $raw, array $flags = ['\\Seen']): void
    {
        $this->folder($folder)->messages()->append($raw, $flags);
    }

    public function uidValidity(string $folder): ?int
    {
        $status = $this->folder($folder)->status();
        $value = $status['UIDVALIDITY'] ?? null;

        return $value === null ? null : (int) $value;
    }

    public function detectSentFolder(): ?string
    {
        $folders = $this->mailbox()->folders()->get();

        foreach ($folders as $folder) {
            if (in_array('\\sent', array_map('strtolower', $folder->flags()), true)) {
                return $folder->path();
            }
        }

        $paths = [];

        foreach ($folders as $folder) {
            $paths[strtolower($folder->path())] = $folder->path();
        }

        foreach (self::SENT_NAMES as $name) {
            if (isset($paths[strtolower($name)])) {
                return $paths[strtolower($name)];
            }
        }

        return null;
    }

    public function check(): void
    {
        $this->mailbox()->folders()->get();
    }

    protected function folder(string $path): FolderInterface
    {
        return $this->mailbox()->folders()->findOrFail($path);
    }

    protected function mailbox(): ImapMailbox
    {
        if (! $this->imap->connected()) {
            // Again right before connecting: a name that was public when the
            // mailbox was saved can point somewhere else now.
            $this->guard->check($this->host);
            $this->imap->connect();
        }

        return $this->imap;
    }

    protected function connection(): ConnectionInterface
    {
        return $this->mailbox()->connection();
    }
}
