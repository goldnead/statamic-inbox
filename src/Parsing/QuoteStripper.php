<?php

namespace Goldnead\StatamicInbox\Parsing;

use EmailReplyParser\Parser\EmailParser;

/**
 * The part of a mail the writer actually wrote, without the quoted history.
 *
 * First our own marker, which every reply sent from the inbox carries above
 * the quote, so an answer to it can be cut exactly. As a fallback
 * willdurand/email-reply-parser, which knows the "Am … schrieb …:" and
 * "On … wrote:" lines of the common clients.
 */
class QuoteStripper
{
    public const MARKER = '##- Bitte oberhalb dieser Zeile antworten / Please reply above this line -##';

    public function strip(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $markerAt = strpos($text, self::MARKER);

        if ($markerAt !== false) {
            // The reply sits above the marker, minus the client's own
            // quote-introducing line if it put one there.
            $text = substr($text, 0, $markerAt);
            $text = (string) preg_replace('/(\n\s*>[^\n]*)+\s*$/u', '', "\n".$text);
            $text = (string) preg_replace('/\n[^\n]*(schrieb|wrote)[^\n]*:\s*$/iu', '', rtrim($text));

            return trim($text);
        }

        $cut = $this->cutAtQuoteHeader($text);

        if ($cut !== null) {
            return $cut;
        }

        try {
            $visible = (new EmailParser)->parse($text)->getVisibleText();
        } catch (\Throwable) {
            return trim($text);
        }

        return trim($visible) === '' ? trim($text) : trim($visible);
    }

    /**
     * "Am So., 20. Sept. 2026 um 14:00 Uhr schrieb Adrian Goldner <" and
     * "adrian@goldner.test>:" on the next line: Gmail wraps its quote header,
     * and the reply parser only knows it on one line. A header counts when
     * it starts with Am/On, says schrieb/wrote, ends with a colon within two
     * lines, and quoted lines follow.
     */
    protected function cutAtQuoteHeader(string $text): ?string
    {
        $lines = explode("\n", $text);

        foreach ($lines as $i => $line) {
            if ($i === 0 || ! preg_match('/^\s*(Am|On)\s/u', $line)) {
                continue;
            }

            $next = $lines[$i + 1] ?? '';
            $oneLine = preg_match('/\b(schrieb|wrote)\b.*:\s*$/u', $line);
            $twoLines = ! $oneLine
                && preg_match('/\b(schrieb|wrote)\b/u', $line.' '.$next)
                && preg_match('/:\s*$/u', $next);

            if (! $oneLine && ! $twoLines) {
                continue;
            }

            $rest = array_slice($lines, $i + ($oneLine ? 1 : 2));
            $quoted = array_filter($rest, fn ($l) => str_starts_with(ltrim($l), '>'));

            if ($quoted === []) {
                continue;
            }

            return trim(implode("\n", array_slice($lines, 0, $i)));
        }

        return null;
    }
}
