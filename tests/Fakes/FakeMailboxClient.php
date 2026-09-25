<?php

namespace Goldnead\StatamicInbox\Tests\Fakes;

use DateTimeInterface;
use Goldnead\StatamicInbox\Contracts\MailboxClient;
use Throwable;

/**
 * An IMAP server in memory, fed with fixture `.eml` files.
 *
 * It behaves like the real thing in the three ways the fetcher depends on:
 * UIDs only ever grow per folder, a first fetch is bounded by a date, and an
 * APPEND puts the message into the folder under the next UID, so the next
 * fetch of that folder sees it like any other message.
 */
class FakeMailboxClient implements MailboxClient
{
    /** @var array<string, array<int, string>> folder => [uid => raw RFC822] */
    public array $folders = [];

    /** @var list<array{folder: string, raw: string, flags: array<int, string>}> */
    public array $appended = [];

    /** @var list<array{method: string, folder: string}> */
    public array $calls = [];

    public ?Throwable $failure = null;

    /** What the server would report as its `\Sent` folder. */
    public ?string $sentFolder = null;

    /** @var array<string, int> folder => UIDVALIDITY; 1 when not set */
    public array $uidValidity = [];

    /** @var array<string, array<int, Throwable>> folder => [uid => what fetching it throws] */
    public array $brokenUids = [];

    /** @var array<string, Throwable> folder => what any call on it throws */
    public array $brokenFolders = [];

    /** Fetching this UID throws, as a message the parser or server chokes on would. */
    public function breakUid(string $folder, int $uid, Throwable $failure): static
    {
        $this->brokenUids[$folder][$uid] = $failure;

        return $this;
    }

    public function repairUid(string $folder, int $uid): static
    {
        unset($this->brokenUids[$folder][$uid]);

        return $this;
    }

    /** Every call on this folder throws; other folders keep working. */
    public function breakFolder(string $folder, Throwable $failure): static
    {
        $this->brokenFolders[$folder] = $failure;

        return $this;
    }

    /**
     * The server renumbered the folder (new UIDVALIDITY): the same messages
     * under new UIDs, starting at 1 again.
     */
    public function renumber(string $folder, int $uidValidity): void
    {
        $messages = array_values($this->folders[$folder] ?? []);
        $this->folders[$folder] = [];
        $this->uidValidity[$folder] = $uidValidity;

        foreach ($messages as $raw) {
            $this->deliver($folder, $raw);
        }
    }

    public function uidValidity(string $folder): ?int
    {
        $this->guard('uidValidity', $folder);

        return $this->uidValidity[$folder] ?? 1;
    }

    /** Put a raw message into a folder, as a delivery would. Returns its UID. */
    public function deliver(string $folder, string $raw): int
    {
        $uids = array_keys($this->folders[$folder] ?? []);
        $uid = $uids === [] ? 1 : max($uids) + 1;

        $this->folders[$folder][$uid] = $raw;

        return $uid;
    }

    /** Every call from now on throws this, as a refused login would. */
    public function failWith(Throwable $failure): static
    {
        $this->failure = $failure;

        return $this;
    }

    public function uidsAfter(string $folder, int $afterUid, ?DateTimeInterface $since = null): array
    {
        $this->guard('uidsAfter', $folder);

        $uids = [];

        foreach ($this->folders[$folder] ?? [] as $uid => $raw) {
            if ($uid <= $afterUid) {
                continue;
            }

            if ($since !== null && ! $this->deliveredOnOrAfter($raw, $since)) {
                continue;
            }

            $uids[] = $uid;
        }

        sort($uids);

        return $uids;
    }

    public function fetchRaw(string $folder, int $uid): string
    {
        $this->guard('fetchRaw', $folder);

        if (isset($this->brokenUids[$folder][$uid])) {
            throw $this->brokenUids[$folder][$uid];
        }

        return $this->folders[$folder][$uid]
            ?? throw new \RuntimeException("No message with UID {$uid} in {$folder}.");
    }

    /** The header block only, as BODY.PEEK[HEADER] returns it. */
    public function fetchHeaders(string $folder, int $uid): string
    {
        $this->guard('fetchHeaders', $folder);

        if (isset($this->brokenUids[$folder][$uid])) {
            throw $this->brokenUids[$folder][$uid];
        }

        $raw = $this->folders[$folder][$uid]
            ?? throw new \RuntimeException("No message with UID {$uid} in {$folder}.");

        return preg_split('/\r?\n\r?\n/', $raw, 2)[0]."\r\n\r\n";
    }

    public function append(string $folder, string $raw, array $flags = ['\\Seen']): void
    {
        $this->guard('append', $folder);

        $this->appended[] = ['folder' => $folder, 'raw' => $raw, 'flags' => $flags];
        $this->deliver($folder, $raw);
    }

    public function detectSentFolder(): ?string
    {
        $this->guard('detectSentFolder', '');

        return $this->sentFolder;
    }

    public function check(): void
    {
        $this->guard('check', '');
    }

    protected function guard(string $method, string $folder): void
    {
        $this->calls[] = ['method' => $method, 'folder' => $folder];

        if ($this->failure) {
            throw $this->failure;
        }

        if ($folder !== '' && isset($this->brokenFolders[$folder])) {
            throw $this->brokenFolders[$folder];
        }
    }

    /** IMAP SEARCH SINCE compares dates, not times; so does this. */
    protected function deliveredOnOrAfter(string $raw, DateTimeInterface $since): bool
    {
        if (! preg_match('/^Date:\s*(.+)$/mi', $raw, $match)) {
            return true;
        }

        $date = strtotime(trim($match[1]));

        return $date === false || date('Y-m-d', $date) >= $since->format('Y-m-d');
    }
}
