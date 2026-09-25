<?php

namespace Goldnead\StatamicInbox\Parsing;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * HTML from a stranger, made safe to show in a sandboxed iframe.
 *
 * Scripts, event handlers, iframes, forms and javascript: links go. Remote
 * images are not loaded: their address moves from `src` into
 * `data-inbox-src`, so a tracking pixel cannot report the read, and the CP
 * swaps them back only when someone clicks "Load images". Inline images
 * (cid:, data:) stay.
 */
class HtmlCleaner
{
    public const REMOTE_ATTRIBUTE = 'data-inbox-src';

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

        // Before sanitising, while `src` still says where the image lives.
        $html = (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $match) use (&$remote): string {
                $tag = $match[0];

                if (! preg_match('/\ssrc\s*=\s*(["\']?)\s*(https?:)?\/\//i', $tag)) {
                    return $tag;
                }

                $remote = true;
                $tag = (string) preg_replace('/\ssrcset\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag);

                return (string) preg_replace('/\ssrc\s*=/i', ' '.self::REMOTE_ATTRIBUTE.'=', $tag, 1);
            },
            $html
        );

        // Background images in inline styles would load just the same.
        if (preg_match('/url\(\s*["\']?\s*(https?:)?\/\//i', $html)) {
            $remote = true;
        }

        return ['html' => $this->sanitizer()->sanitize($html), 'has_remote_images' => $remote];
    }

    protected function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowMediaSchemes(['cid', 'data'])
                ->allowRelativeLinks(false)
                ->allowRelativeMedias(false)
                ->allowAttribute(self::REMOTE_ATTRIBUTE, ['img'])
                ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
                ->forceAttribute('a', 'target', '_blank')
                ->withMaxInputLength(2_000_000)
        );
    }
}
