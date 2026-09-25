<?php

namespace Goldnead\StatamicInbox\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\StatamicInbox\Support\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $brand_id
 * @property int $mailbox_id
 * @property string $subject
 * @property string $subject_normalized
 * @property int|null $contact_id
 * @property string $counterpart_email
 * @property string $status
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $last_message_at
 * @property bool $unread
 * @property-read Mailbox $mailbox
 */
class Conversation extends Model
{
    use HasBrand;

    public const STATUS_OPEN = 'open';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_WAITING, self::STATUS_CLOSED];

    protected $table = 'inbox_conversations';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_OPEN,
        'unread' => true,
        'subject' => '',
        'subject_normalized' => '',
        'counterpart_email' => '',
    ];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'snoozed_until' => 'datetime',
            'last_message_at' => 'datetime',
            'unread' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $conversation): void {
            if ($conversation->isDirty('subject')) {
                $conversation->subject_normalized = Subject::normalize((string) $conversation->subject);
            }

            if ($conversation->isDirty('counterpart_email')) {
                $conversation->counterpart_email = strtolower(trim((string) $conversation->counterpart_email));
            }
        });
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('sent_at')->orderBy('id');
    }
}
