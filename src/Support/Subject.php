<?php

namespace Goldnead\StatamicInbox\Support;

/**
 * Subjects as the threading compares them and as a reply writes them.
 */
class Subject
{
    /** Re:, AW:, Fwd:, WG:, SV:, and the like, possibly stacked or numbered. */
    protected const PREFIX = '/^\s*((re|aw|antw|fw|fwd|wg|sv|vs|tr|r)(\[\d+\]|\(\d+\))?\s*:\s*)+/iu';

    public static function normalize(string $subject): string
    {
        $subject = (string) preg_replace(self::PREFIX, '', $subject);
        $subject = (string) preg_replace('/\s+/u', ' ', $subject);

        return mb_strtolower(trim($subject));
    }

    /** "Re: " once, never "Re: Re: ". */
    public static function reply(string $subject): string
    {
        $bare = trim((string) preg_replace(self::PREFIX, '', $subject));

        return 'Re: '.$bare;
    }
}
