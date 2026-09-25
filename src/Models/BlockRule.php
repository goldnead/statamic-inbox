<?php

namespace Goldnead\StatamicInbox\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A hidden sender or domain of one mailbox. Mail from it is not stored;
 * removing the rule lets future mail through again.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property string $type sender|domain
 * @property string $value
 */
class BlockRule extends Model
{
    use HasBrand;

    public const SENDER = 'sender';

    public const DOMAIN = 'domain';

    protected $table = 'inbox_block_rules';

    protected $guarded = ['id'];

    /**
     * Domains where every address is somebody else. Hiding one of them
     * would hide half of all customers, so only single senders there.
     */
    public const FREEMAIL = [
        'gmail.com', 'googlemail.com', 'gmx.de', 'gmx.net', 'gmx.at', 'gmx.ch', 'web.de', 't-online.de',
        'outlook.com', 'outlook.de', 'hotmail.com', 'hotmail.de', 'live.com', 'live.de', 'msn.com',
        'yahoo.com', 'yahoo.de', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'posteo.de', 'posteo.net',
        'mailbox.org', 'freenet.de', 'arcor.de', 'proton.me', 'protonmail.com', 'gmx.com', 'mail.de', 'online.de',
    ];

    public static function canHideDomainOf(string $email): bool
    {
        $domain = self::domainOf($email);

        return $domain !== '' && ! in_array($domain, [...self::FREEMAIL, ...(array) config('inbox.filter.freemail_domains', [])], true);
    }

    public static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    public function matches(string $email): bool
    {
        $email = strtolower(trim($email));

        return $this->type === self::SENDER
            ? $email === $this->value
            : self::domainOf($email) === $this->value;
    }

    /**
     * The conversations of the mailbox this rule covers.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function applyTo(Builder $query): Builder
    {
        return $this->type === self::SENDER
            ? $query->where('counterpart_email', $this->value)
            : $query->where('counterpart_email', 'like', '%@'.str_replace(['%', '_'], ['\%', '\_'], $this->value));
    }
}
