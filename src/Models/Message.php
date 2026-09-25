<?php

namespace Goldnead\StatamicInbox\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One mail in a conversation, incoming or outgoing.
 *
 * `message_id`, `in_reply_to` and every entry of `references` are stored
 * without angle brackets, so threading compares plain strings.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $mailbox_id
 * @property int $conversation_id
 * @property string $direction
 * @property string $message_id
 * @property string|null $in_reply_to
 * @property string|null $references
 * @property string $from_email
 * @property string|null $from_name
 * @property array<int, array{email: string, name: string|null}>|null $to
 * @property array<int, array{email: string, name: string|null}>|null $cc
 * @property string $subject
 * @property string|null $text
 * @property string|null $html_sanitized
 * @property string|null $body_stripped
 * @property Carbon|null $sent_at
 * @property string|null $folder
 * @property int|null $imap_uid
 * @property bool $has_remote_images
 * @property string|null $send_error
 * @property-read Conversation $conversation
 * @property-read Mailbox $mailbox
 */
class Message extends Model
{
    use HasBrand;

    public const IN = 'in';

    public const OUT = 'out';

    protected $table = 'inbox_messages';

    protected $guarded = ['id'];

    protected $attributes = [
        'has_remote_images' => false,
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'sent_at' => 'datetime',
            'imap_uid' => 'integer',
            'has_remote_images' => 'boolean',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Mailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return list<string> */
    public function referenceIds(): array
    {
        return array_values(array_filter(explode(' ', (string) $this->references)));
    }
}
