<?php

namespace Goldnead\StatamicInbox\Parsing;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * HTML from a stranger, made safe to show in a sandboxed iframe.
 *
 * Scripts, event handlers, iframes, forms and javascript: links go.
 *
 * Remote images are not loaded, whichever attribute carries them: a remote
 * `src` on `<img>` or `<source>` moves into `data-inbox-src`, and every
 * `srcset` is dropped outright (a browser picks from srcset even when src is
 * harmless, so a tracking pixel hidden there would report the read). The CP
 * swaps `data-inbox-src` back only when someone clicks "Load images".
 *
 * Inline images (`cid:`) move into `data-inbox-cid`; the CP resolves them
 * through the authorised attachment route using the message's Content-ID
 * map, since a cid: URL means nothing to a browser. `data:` images stay.
 */
class HtmlCleaner
{
    public const REMOTE_ATTRIBUTE = 'data-inbox-src';

    public const CID_ATTRIBUTE = 'data-inbox-cid';

    protected const REMOTE_URL = '/^\s*(https?:)?\/\//i';

    protected ?HtmlSanitizer $sanitizer = null;

    /**
     * @return array{html: string|null, has_remote_images: bool}
     */
    public function clean(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return ['html' => null, 'has_remote_images' => false];
        }

        $remote = false;

        // Before sanitising, while the attributes still say where things live.
        $html = (string) preg_replace_callback(
            '/<(img|source)\b[^>]*>/i',
            function (array $match) use (&$remote): string {
                return $this->rewriteTag($match[0], $remote);
            },
            $html
        );

        // Background images in inline styles would load just the same.
        if (preg_match('/url\(\s*["\']?\s*(https?:)?\/\//i', $html)) {
            $remote = true;
        }

        return ['html' => $this->sanitizer()->sanitize($html), 'has_remote_images' => $remote];
    }

    protected function rewriteTag(string $tag, bool &$remote): string
    {
        $attribute = '/\s(%s)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';

        // srcset: flag it when it points anywhere remote, and drop it always.
        $tag = (string) preg_replace_callback(sprintf($attribute, 'srcset'), function (array $m) use (&$remote): string {
            $value = $m[3] !== '' ? $m[3] : ($m[4] ?? '').($m[5] ?? '');

            if (preg_match('/(^|,)\s*(https?:)?\/\//i', $value)) {
                $remote = true;
            }

            return '';
        }, $tag);

        return (string) preg_replace_callback(sprintf($attribute, 'src'), function (array $m) use (&$remote): string {
            $value = $m[3] !== '' ? $m[3] : ($m[4] ?? '').($m[5] ?? '');

            if (preg_match(self::REMOTE_URL, $value)) {
                $remote = true;

                return ' '.self::REMOTE_ATTRIBUTE.'="'.htmlspecialchars($value, ENT_QUOTES).'"';
            }

            if (preg_match('/^\s*cid:(.+)$/i', $value, $cid)) {
                return ' '.self::CID_ATTRIBUTE.'="'.htmlspecialchars(trim($cid[1], ' <>'), ENT_QUOTES).'"';
            }

            return $m[0];
        }, $tag, 1);
    }

    protected function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowMediaSchemes(['data'])
                ->allowRelativeLinks(false)
                ->allowRelativeMedias(false)
                ->allowAttribute(self::REMOTE_ATTRIBUTE, ['img'])
                ->allowAttribute(self::CID_ATTRIBUTE, ['img'])
                ->dropAttribute('srcset', '*')
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->forceAttribute('a', 'target', '_blank')
                ->withMaxInputLength(2_000_000)
        );
    }
}
