<?php

namespace Goldnead\StatamicInbox\Parsing;

use Carbon\CarbonImmutable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\Header\IdHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;

/**
 * RFC822 in, {@see ParsedMessage} out, via zbateson/mail-mime-parser.
 */
class MessageParser
{
    public function parse(string $raw): ParsedMessage
    {
        $message = Message::from($raw, false);

        $from = $this->addresses($message, 'From')[0] ?? ['email' => '', 'name' => null];
        $subject = trim((string) $message->getSubject());
        $text = $message->getTextContent();
        $html = $message->getHtmlContent();
        $sentAt = $this->date($message);

        $messageId = $this->id($message, 'Message-ID');
        $generated = $messageId === null;

        // No Message-ID: derive one from what identifies the mail, so the
        // same message fetched twice gets the same id (FreeScout does the
        // same). Received headers and the like are left out on purpose.
        $messageId ??= sha1(implode("\n", [
            $from['email'],
            (string) $message->getHeaderValue('To'),
            (string) $message->getHeaderValue('Date'),
            $subject,
            (string) $text,
            (string) $html,
        ])).'@inbox.generated';

        return new ParsedMessage(
            messageId: $messageId,
            messageIdGenerated: $generated,
            inReplyTo: $this->id($message, 'In-Reply-To'),
            references: $this->ids($message, 'References'),
            fromEmail: strtolower($from['email']),
            fromName: $from['name'],
            to: $this->addresses($message, 'To'),
            cc: $this->addresses($message, 'Cc'),
            subject: $subject,
            text: $text === null ? null : $this->normalizeNewlines($text),
            html: $html,
            sentAt: $sentAt,
            attachments: $this->attachments($message),
            bcc: $this->addresses($message, 'Bcc'),
            filterHeaders: $this->filterHeaders($message),
        );
    }

    /**
     * The headers the filter decides on, from a header block alone (what
     * `BODY.PEEK[HEADER]` returns) or a whole message.
     *
     * @return array<string, mixed>
     */
    public function headersOnly(string $raw): array
    {
        return $this->filterHeaders(Message::from($raw, false));
    }

    /**
     * Small on purpose: names, not values, except where the value decides.
     * Stored with every message so a changed rule can be applied again
     * without asking the server.
     *
     * @return array<string, mixed>
     */
    protected function filterHeaders(IMessage $message): array
    {
        $names = [];

        foreach ($message->getAllHeaders() as $header) {
            $names[strtolower($header->getName())] = true;
        }

        $value = fn (string $name) => ($v = $message->getHeaderValue($name)) === null ? null : strtolower(trim((string) $v));

        // Return-Path: <> reads as an empty value; keep "<>" so it is
        // distinguishable from a missing header.
        $returnPath = null;
        foreach ($message->getRawHeaders() as [$name, $raw]) {
            if (strtolower((string) $name) === 'return-path') {
                $returnPath = trim((string) $raw);
                break;
            }
        }

        return [
            'header_names' => array_keys($names),
            'return_path' => $returnPath,
            'precedence' => $value('Precedence'),
            'auto_submitted' => $value('Auto-Submitted'),
            'from' => strtolower((string) ($this->addresses($message, 'From')[0]['email'] ?? '')),
            // A contact form or a booking tool sends as noreply@ and puts
            // the person in Reply-To.
            'reply_to' => array_column($this->addresses($message, 'Reply-To'), 'email'),
            'recipients' => [
                'to' => array_column($this->addresses($message, 'To'), 'email'),
                'cc' => array_column($this->addresses($message, 'Cc'), 'email'),
                'bcc' => array_column($this->addresses($message, 'Bcc'), 'email'),
            ],
            'undisclosed' => str_contains(strtolower((string) $message->getHeaderValue('To')), 'undisclosed'),
        ];
    }

    protected function id(IMessage $message, string $header): ?string
    {
        return $this->ids($message, $header)[0] ?? null;
    }

    /** @return list<string> */
    protected function ids(IMessage $message, string $header): array
    {
        $value = $message->getHeader($header);

        if ($value instanceof IdHeader) {
            $ids = $value->getIds();
        } else {
            preg_match_all('/<([^<>\s]+)>/', (string) $message->getHeaderValue($header), $matches);
            $ids = $matches[1];
        }

        return array_values(array_filter(array_map(
            fn ($id) => trim((string) $id, " \t<>"),
            $ids
        )));
    }

    /** @return list<array{email: string, name: string|null}> */
    protected function addresses(IMessage $message, string $header): array
    {
        $value = $message->getHeader($header);

        if (! $value instanceof AddressHeader) {
            return [];
        }

        $addresses = [];

        foreach ($value->getAddresses() as $address) {
            $email = strtolower(trim($address->getEmail()));

            if ($email === '') {
                continue;
            }

            $name = trim((string) $address->getName());
            $addresses[] = ['email' => $email, 'name' => $name === '' ? null : $name];
        }

        return $addresses;
    }

    protected function date(IMessage $message): ?CarbonImmutable
    {
        $header = $message->getHeader('Date');
        $date = $header instanceof DateHeader ? $header->getDateTimeImmutable() : null;

        return $date ? CarbonImmutable::instance($date)->utc() : null;
    }

    /** @return list<array{filename: string, mime: string, content_id: string|null, content: string}> */
    protected function attachments(IMessage $message): array
    {
        $attachments = [];

        foreach ($message->getAllAttachmentParts() as $index => $part) {
            $content = $part->getBinaryContentStream()?->getContents();

            if ($content === null) {
                continue;
            }

            $contentId = trim((string) $part->getContentId(), " \t<>");

            $attachments[] = [
                'filename' => $this->safeFilename($part->getFilename() ?: 'attachment-'.($index + 1)),
                'mime' => strtolower($part->getContentType('application/octet-stream')),
                'content_id' => $contentId === '' ? null : $contentId,
                'content' => $content,
            ];
        }

        return $attachments;
    }

    protected function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return $name === '' || $name === '.' || $name === '..' ? 'attachment' : mb_substr($name, 0, 200);
    }

    protected function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
