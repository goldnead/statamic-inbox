<?php

namespace Goldnead\StatamicInbox\Sending;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicInbox\Exceptions\ReplyRefused;
use Goldnead\StatamicInbox\Integrations\SuppressionCheck;
use Goldnead\StatamicInbox\Models\Attachment;
use Goldnead\StatamicInbox\Models\Conversation;
use Goldnead\StatamicInbox\Models\Message;
use Goldnead\StatamicInbox\Parsing\QuoteStripper;
use Goldnead\StatamicInbox\Support\MessageIds;
use Goldnead\StatamicInbox\Support\Subject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Answers a conversation from the mailbox it belongs to.
 *
 * The reply is stored at once as an outgoing message, so the text is never
 * lost, and then sent by {@see SendReply} on the queue. The suppression check
 * happens here, before anything is stored or queued: a refused reply leaves
 * no trace but the exception the CP shows.
 */
class ReplySender
{
    public function __construct(protected SuppressionCheck $suppression, protected Signatures $signatures) {}

    /**
     * @param  list<UploadedFile>  $attachments
     * @param  string|null  $signature  a signature id, Signatures::NONE, or null for the one the form would preselect
     *
     * @throws ReplyRefused
     */
    public function send(Conversation $conversation, string $text, array $attachments = [], ?string $signature = null): Message
    {
        return BrandContext::runFor((int) $conversation->brand_id, function () use ($conversation, $text, $attachments, $signature) {
            $recipient = strtolower(trim($conversation->counterpart_email));

            if ($recipient === '') {
                throw ReplyRefused::noRecipient();
            }

            $this->suppression->assertMayReply($recipient);

            // One unit: an attachment the disk refuses leaves no reply behind.
            $message = DB::transaction(fn () => $this->store($conversation, $recipient, $text, $attachments, $signature));

            SendReply::dispatch($message->id)->onQueue((string) config('inbox.queue', 'default'));

            return $message->fresh() ?? $message;
        });
    }

    /** @param  list<UploadedFile>  $attachments */
    protected function store(Conversation $conversation, string $recipient, string $text, array $attachments, ?string $signature): Message
    {
        $mailbox = $conversation->mailbox;
        $history = $conversation->messages()->get();

        // The newest incoming message is the one we answer; with none, the
        // newest message at all (a follow-up to our own mail).
        $parent = $history->where('direction', Message::IN)->last() ?? $history->last();

        $references = $parent === null ? [] : array_values(array_unique(array_filter([
            // Stored hashed when too long for the index; never put a hash on the wire.
            ...($parent->referenceIds() ?: array_filter([$parent->in_reply_to], fn ($id) => ! str_starts_with((string) $id, 'sha256:'))),
            $parent->message_id_full ?? $parent->message_id,
        ])));

        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $now = Carbon::now();

        // Under the text, above the quote; "-- " is the separator mail
        // programs know.
        $chosen = $this->signatures->resolve($mailbox, $conversation, $signature);
        $signed = $chosen === null ? '' : trim($this->signatures->render($chosen, $mailbox));
        $body = $signed === '' ? $text : $text."\n\n-- \n".$signed;

        $message = Message::create([
            'mailbox_id' => $mailbox->id,
            'conversation_id' => $conversation->id,
            'direction' => Message::OUT,
            'message_id' => 'inbox.'.Str::uuid()->toString().'@'.$mailbox->domain(),
            'in_reply_to' => $parent?->message_id,
            'references' => implode(' ', $references) ?: null,
            'from_email' => strtolower($mailbox->email),
            'from_name' => $mailbox->senderName() ?: null,
            'to' => [['email' => $recipient, 'name' => null]],
            'cc' => [],
            'subject' => MessageIds::fit(Subject::reply($conversation->subject !== '' ? $conversation->subject : (string) $parent?->subject)),
            'text' => $this->withQuote($body, $parent),
            'body_stripped' => $body,
            'sent_at' => $now,
            'folder' => $mailbox->sent_folder,
        ]);

        $this->storeAttachments($message, $attachments);

        $conversation->forceFill([
            'status' => Conversation::STATUS_WAITING,
            'unread' => false,
            'snoozed_until' => null,
            'last_message_at' => $now,
        ])->save();

        return $message;
    }

    /**
     * The reply, our marker, and the answered message quoted underneath.
     * The marker lets the next answer be cut exactly ({@see QuoteStripper}).
     */
    protected function withQuote(string $text, ?Message $parent): string
    {
        if ($parent === null) {
            return $text;
        }

        $quoted = collect(explode("\n", (string) ($parent->body_stripped ?? $parent->text)))
            ->map(fn ($line) => '> '.$line)
            ->implode("\n");

        $who = $parent->from_name ? $parent->from_name.' <'.$parent->from_email.'>' : $parent->from_email;
        $when = $parent->sent_at?->copy()->setTimezone((string) config('app.timezone'))->format('d.m.Y H:i');

        return $text."\n\n".QuoteStripper::MARKER."\n\n"
            .__('On :date, :who wrote:', ['date' => (string) $when, 'who' => $who])."\n"
            .$quoted;
    }

    /**
     * The HTML part of a reply, made from its text part: everything escaped,
     * line breaks kept, http(s) links clickable, the quote under our marker
     * as a blockquote. No markup from what was typed (the signature
     * included) reaches the mail.
     */
    public static function html(string $text, ?string $body = null): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $body = $body === null ? null : str_replace(["\r\n", "\r"], "\n", $body);

        // Where the quote starts: right after what was written (text and
        // signature, stored as `body_stripped`), so a marker typed into the
        // text or the signature does not cut it early. Without it, the last
        // marker standing on its own between blank lines.
        if ($body !== null && str_starts_with($text, $body)) {
            $after = substr($text, strlen($body));
            $rest = ltrim($after);
            $markerAt = str_starts_with($rest, QuoteStripper::MARKER) ? strlen($text) - strlen($rest) : false;
        } else {
            $found = strrpos($text, "\n\n".QuoteStripper::MARKER."\n\n");
            $markerAt = $found === false ? false : $found + 2;
        }

        $written = $markerAt === false ? $text : rtrim(substr($text, 0, $markerAt));
        $html = '<div>'.Signatures::html($written).'</div>';

        if ($markerAt === false) {
            return $html;
        }

        $html .= "\n<p>".Signatures::html(QuoteStripper::MARKER).'</p>';
        $intro = [];
        $quoted = [];

        foreach (explode("\n", trim(substr($text, $markerAt + strlen(QuoteStripper::MARKER)))) as $line) {
            if (str_starts_with($line, '>')) {
                $quoted[] = (string) preg_replace('/^> ?/', '', $line);
            } elseif ($quoted === []) {
                $intro[] = $line;
            } else {
                $quoted[] = $line;
            }
        }

        if (trim(implode("\n", $intro)) !== '') {
            $html .= "\n<div>".Signatures::html(trim(implode("\n", $intro))).'</div>';
        }

        return $html."\n<blockquote type=\"cite\">".Signatures::html(implode("\n", $quoted)).'</blockquote>';
    }

    /** @param  list<UploadedFile>  $files */
    protected function storeAttachments(Message $message, array $files): void
    {
        $disk = (string) config('inbox.attachments.disk', 'local');
        $base = trim((string) config('inbox.attachments.path', 'inbox/attachments'), '/').'/'.$message->id;

        foreach (array_values($files) as $index => $file) {
            $name = basename(str_replace('\\', '/', $file->getClientOriginalName())) ?: 'attachment';
            $path = $base.'/'.($index + 1).'-'.$name;

            if (! Storage::disk($disk)->put($path, (string) file_get_contents($file->getRealPath()))) {
                throw new \RuntimeException("Could not store attachment {$name} on the inbox disk.");
            }

            Attachment::create([
                'message_id' => $message->id,
                'filename' => $name,
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => (int) $file->getSize(),
                'path' => $path,
            ]);
        }
    }
}
