<?php

namespace Goldnead\StatamicInbox\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\BrandContext\Contracts\SenderIdentityResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One IMAP/SMTP account the inbox fetches and replies from.
 *
 * The app password is encrypted at rest and hidden from every serialisation:
 * it never reaches the CP, a log line or a JSON response.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string $email
 * @property string|null $from_name
 * @property string $imap_host
 * @property int $imap_port
 * @property string $imap_encryption
 * @property string $username
 * @property string $password
 * @property string $smtp_host
 * @property int $smtp_port
 * @property string $smtp_encryption
 * @property string $inbox_folder
 * @property string|null $sent_folder
 * @property bool $append_sent
 * @property int $last_uid_inbox
 * @property int $last_uid_sent
 * @property Carbon|null $last_fetched_at
 * @property string|null $last_error
 * @property string|null $last_error_scope
 * @property array<string, string>|null $folder_errors
 * @property int|null $uidvalidity_inbox
 * @property int|null $uidvalidity_sent
 * @property Carbon|null $import_since
 * @property bool $active
 */
class Mailbox extends Model
{
    use HasBrand;

    protected $table = 'inbox_mailboxes';

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected $attributes = [
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'inbox_folder' => 'INBOX',
        'last_uid_inbox' => 0,
        'last_uid_sent' => 0,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'append_sent' => 'boolean',
            'last_uid_inbox' => 'integer',
            'last_uid_sent' => 'integer',
            'uidvalidity_inbox' => 'integer',
            'uidvalidity_sent' => 'integer',
            'folder_errors' => 'array',
            'last_fetched_at' => 'datetime',
            'import_since' => 'date',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $mailbox): void {
            // Gmail files mail sent over its SMTP into Sent by itself; an
            // APPEND on top would put every reply there twice.
            if ($mailbox->getAttribute('append_sent') === null) {
                $mailbox->append_sent = ! static::isGmailHost((string) $mailbox->imap_host)
                    && ! static::isGmailHost((string) $mailbox->smtp_host);
            }

            if ($mailbox->getAttribute('import_since') === null) {
                $mailbox->import_since = Carbon::now()->subDays((int) config('inbox.fetch.import_days', 90))->startOfDay();
            }
        });
    }

    /** imap.gmail.com, smtp.gmail.com, imap.googlemail.com and the like. */
    public static function isGmailHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));

        foreach (['gmail.com', 'googlemail.com'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the whole mailbox is down (login, connection, every folder).
     * A failing Sent folder or one bad message is not that.
     */
    public function isBroken(): bool
    {
        return $this->last_error !== null && $this->last_error_scope === 'mailbox';
    }

    /**
     * The display name replies go out under: the mailbox's own sender name,
     * else the brand's sender name, else none (the bare address). Never
     * `name`, which is only the label in the CP list.
     */
    public function senderName(): string
    {
        $own = trim((string) $this->from_name);

        if ($own !== '') {
            return $own;
        }

        try {
            return trim((string) app(SenderIdentityResolver::class)->resolve((int) $this->brand_id)->fromName);
        } catch (\Throwable) {
            return '';
        }
    }

    /** The part after the @, used for our own Message-IDs. */
    public function domain(): string
    {
        $at = strrpos($this->email, '@');

        return $at === false ? 'localhost' : strtolower(substr($this->email, $at + 1));
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Every sensitive string an error message about this mailbox could carry.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $password = (string) $this->password;

        return array_values(array_filter([
            $password,
            base64_encode($password),
            base64_encode("\0".$this->username."\0".$password),
            rawurlencode($password),
        ], fn ($secret) => strlen($secret) >= 3));
    }
}
