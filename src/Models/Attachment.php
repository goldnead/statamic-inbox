<?php

namespace Goldnead\StatamicInbox\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file that came with a message. Lives on a private disk and is only served
 * through the CP route that checks `view inbox`.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $message_id
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property string $path
 */
class Attachment extends Model
{
    use HasBrand;

    /** Shown in the browser; everything else is a download. */
    public const INLINE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    protected $table = 'inbox_attachments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isInline(): bool
    {
        return in_array(strtolower($this->mime), self::INLINE_MIMES, true);
    }
}
