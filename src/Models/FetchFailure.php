<?php

namespace Goldnead\StatamicInbox\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A UID that could not be fetched or stored. The cursor has already moved
 * past it; the fetcher retries it up to {@see self::MAX_ATTEMPTS} times and
 * then gives up, visibly (`gave_up_at`).
 *
 * @property int $id
 * @property int $mailbox_id
 * @property string $folder
 * @property int $uid
 * @property string $error
 * @property int $attempts
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $gave_up_at
 */
class FetchFailure extends Model
{
    use HasBrand;

    public const MAX_ATTEMPTS = 3;

    protected $table = 'inbox_fetch_failures';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'attempts' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'gave_up_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
