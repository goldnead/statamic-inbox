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
