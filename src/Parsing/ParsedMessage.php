<?php

namespace Goldnead\StatamicInbox\Parsing;

use Carbon\CarbonImmutable;

/**
 * What the parser read out of one RFC822 message. Ids without angle brackets.
 */
final class ParsedMessage
{
    /**
     * @param  list<string>  $references
     * @param  list<array{email: string, name: string|null}>  $to
     * @param  list<array{email: string, name: string|null}>  $cc
     * @param  list<array{filename: string, mime: string, content: string}>  $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly bool $messageIdGenerated,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly array $cc,
        public readonly string $subject,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly ?CarbonImmutable $sentAt,
        public readonly array $attachments = [],
    ) {}

    /**
     * Every id this message points back to, In-Reply-To first, then the
     * References from newest to oldest.
     *
     * @return list<string>
     */
    public function ancestors(): array
    {
        return array_values(array_unique(array_filter([
            $this->inReplyTo,
            ...array_reverse($this->references),
        ])));
    }
}
