<?php

namespace Goldnead\StatamicInbox\Filtering;

use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Models\SkippedMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes conversations in Statamic, attachment files included, and leaves a
 * skip record per message so the next fetch does not bring them back. The
 * mail server is never touched: the mails stay in Gmail.
 */
class ConversationPurger
{
    /**
     * @param  iterable<Conversation>  $conversations
     * @param  string|callable(Message): string  $reason
     * @return int messages removed
     */
    public function purge(iterable $conversations, string|callable $reason): int
    {
        $removed = 0;
        $paths = [];

        DB::transaction(function () use ($conversations, $reason, &$removed, &$paths) {
            foreach ($conversations as $conversation) {
                $messages = Message::query()->where('conversation_id', $conversation->id)->get();

                foreach ($messages as $message) {
                    $why = is_callable($reason) ? $reason($message) : $reason;

                    SkippedMessage::query()->firstOrCreate(
                        [
                            'mailbox_id' => $message->mailbox_id,
                            // Hashed, as the fetcher writes it (see SkippedMessage).
                            'message_id' => SkippedMessage::keyFor($message->message_id_full ?? $message->message_id),
                        ],
                        [
                            'folder' => (string) ($message->folder ?? ''),
                            'uid' => $message->imap_uid,
                            'sender' => $why === 'blocked' ? $conversation->counterpart_email : null,
                            'reason' => $why,
                            'skipped_at' => Carbon::now(),
                        ]
                    );

                    $attachments = Attachment::query()->where('message_id', $message->id);
                    $paths = [...$paths, ...$attachments->pluck('path')->all()];
                    $attachments->delete();
                    $message->delete();
                    $removed++;
                }

                $conversation->delete();
            }
        });

        // After the commit: a rolled-back purge keeps its files.
        if ($paths !== []) {
            try {
                Storage::disk((string) config('inbox.attachments.disk', 'local'))->delete($paths);
            } catch (Throwable) {
                // Orphaned files on a private disk are clutter, not a leak.
            }
        }

        return $removed;
    }
}
