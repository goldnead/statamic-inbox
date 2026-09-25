<?php

namespace Goldnead\StatamicInbox\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A mail the filter left out: bulk mail, a mail to yourself, or one from a
 * hidden sender. Nothing of its content is kept; the record exists so the
 * dedupe sees it and the mailbox page can count it.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property string $folder
 * @property int|null $uid
 * @property string $message_id
 * @property string|null $sender
 * @property string $reason
 * @property Carbon $skipped_at
 */
class SkippedMessage extends Model
{
    use HasBrand;

    /** Reasons that mean "bulk mail", as the mailbox page counts them. */
    public const BULK_REASONS = [
        'list_header', 'precedence', 'auto_submitted', 'bulk_sender_header',
        'noreply_sender', 'bounce', 'mass_outgoing',
    ];

    protected $table = 'inbox_skipped_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['uid' => 'integer', 'skipped_at' => 'datetime'];
    }
}
