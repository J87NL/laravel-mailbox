<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Html;

use Closure;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class MessageHtml
{
    private const REMOTE_REFERENCE = '/(<img[^>]+src|background)\s*=\s*["\']?\s*https?:|srcset\s*=\s*["\']?[^"\'>]*(https?:)?\/\/|url\(\s*["\']?\s*(https?:)?\/\//i';

    public const CID_ATTRIBUTE = 'data-mailbox-cid';

    private const INLINE_PLACEHOLDER = 'data:mailbox-inline-';

    private const RESOURCE_LOADING_CSS = '/(url|image|image-set|cross-fade|element)\(|\\\\|@import/';

    public function render(?string $html, ?string $text, Closure $cidUrl, bool $allowRemoteImages = false): string
    {
        if (is_null($html) || trim($html) === '') {
            return sprintf('<pre style="white-space: pre-wrap; font: inherit">%s</pre>', e((string) $text));
        }

        $remoteImages = $this->remoteImagesAllowed($allowRemoteImages);
        $inlineHosts = [];
        $withInlineUrls = $this->rewriteContentIds($html, $cidUrl, $inlineHosts);
        $clean = $this->sanitizer($remoteImages, $inlineHosts)->sanitize($withInlineUrls);

        if ($remoteImages) {
            return $clean;
        }

        return $this->stripResourceLoadingStyles($clean);
    }

    public function quote(?string $html, ?string $text, Closure $cidUrl): string
    {
        if (is_null($html) || trim($html) === '') {
            return nl2br(e((string) $text));
        }

        $inlineUrls = [];
        $marked = $this->markContentIds($this->withoutCidMarkers($html), $cidUrl, $inlineUrls);

        $config = $this->baseConfig()
            ->allowMediaSchemes([
                'data',
                'https',
            ])
            ->dropAttribute('srcset', '*')
            ->allowAttribute(self::CID_ATTRIBUTE, 'img');

        $clean = $this->stripResourceLoadingStyles((new HtmlSanitizer($config))->sanitize($marked));

        return preg_replace_callback(
            sprintf('/%s(\d+)/', preg_quote(self::INLINE_PLACEHOLDER, '/')),
            fn (array $match): string => e($inlineUrls[(int) $match[1]] ?? ''),
            $clean,
        ) ?? $clean;
    }

    public function sanitizeSignature(?string $html): ?string
    {
        if (is_null($html) || trim(strip_tags($html, '<img>')) === '') {
            return null;
        }

        $config = $this->baseConfig()
            ->allowMediaSchemes([
                'data',
                'https',
            ]);

        return $this->stripResourceLoadingStyles((new HtmlSanitizer($config))->sanitize($html));
    }

    public function remoteImagesAllowed(bool $requested = false): bool
    {
        return $requested || ! config('mailbox.html.block_remote_images');
    }

    public function hasRemoteImages(?string $html): bool
    {
        return ! is_null($html) && preg_match(self::REMOTE_REFERENCE, $html) === 1;
    }

    private function stripResourceLoadingStyles(string $html): string
    {
        return preg_replace_callback(
            '/\sstyle="([^"]*)"/i',
            fn (array $match): string => $this->safeStyleAttribute($match[1]),
            $html,
        ) ?? $html;
    }

    private function safeStyleAttribute(string $encoded): string
    {
        $declarations = collect(explode(';', html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5)))
            ->map(fn (string $declaration): string => trim($declaration))
            ->filter()
            ->reject(fn (string $declaration): bool => $this->loadsResources($declaration));

        if ($declarations->isEmpty()) {
            return '';
        }

        return sprintf(' style="%s"', e($declarations->implode('; ')));
    }

    private function loadsResources(string $declaration): bool
    {
        $normalized = strtolower((string) preg_replace([
            '/\/\*.*?\*\//s',
            '/\s+/',
        ], '', $declaration));

        return preg_match(self::RESOURCE_LOADING_CSS, $normalized) === 1;
    }

    private function withoutCidMarkers(string $html): string
    {
        return preg_replace(
            sprintf('/\s%s\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', preg_quote(self::CID_ATTRIBUTE, '/')),
            '',
            $html,
        ) ?? $html;
    }

    /**
     * @param  array<int, string>  $inlineUrls
     */
    private function markContentIds(string $html, Closure $cidUrl, array &$inlineUrls): string
    {
        return preg_replace_callback(
            '/(<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*?(?<![\w-])src\s*=\s*)(["\'])cid:([^"\']+)\2/i',
            function (array $match) use ($cidUrl, &$inlineUrls): string {
                $contentId = html_entity_decode($match[3]);
                $inlineUrls[] = (string) $cidUrl($contentId);

                return sprintf('%1$s%2$s%3$s%4$d%2$s %5$s="%6$s"', $match[1], $match[2], self::INLINE_PLACEHOLDER, count($inlineUrls) - 1, self::CID_ATTRIBUTE, e($contentId));
            },
            $html,
        ) ?? $html;
    }

    /**
     * @param  array<int, string>  $hosts
     */
    private function rewriteContentIds(string $html, Closure $cidUrl, array &$hosts): string
    {
        return preg_replace_callback(
            '/(["\'])cid:([^"\']+)\1/i',
            function (array $match) use ($cidUrl, &$hosts): string {
                $url = (string) $cidUrl(html_entity_decode($match[2]));
                $hosts[] = (string) parse_url($url, PHP_URL_HOST);

                return sprintf('%1$s%2$s%1$s', $match[1], e($url));
            },
            $html,
        ) ?? $html;
    }

    /**
     * @param  array<int, string>  $inlineHosts
     * @return array<int, string>
     */
    private function mediaSchemes(bool $allowRemoteImages, array $inlineHosts): array
    {
        if ($allowRemoteImages || $inlineHosts !== []) {
            return [
                'data',
                'http',
                'https',
            ];
        }

        return [
            'data',
        ];
    }

    /**
     * @param  array<int, string>  $inlineHosts
     */
    private function sanitizer(bool $allowRemoteImages, array $inlineHosts): HtmlSanitizer
    {
        $inlineHosts = array_values(array_unique(array_filter($inlineHosts)));

        $config = $this->baseConfig()
            ->allowMediaSchemes($this->mediaSchemes($allowRemoteImages, $inlineHosts))
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank');

        if ($allowRemoteImages) {
            return new HtmlSanitizer($config);
        }

        $config = $config->dropAttribute('srcset', '*');

        if ($inlineHosts === []) {
            return new HtmlSanitizer($config);
        }

        return new HtmlSanitizer($config->allowMediaHosts($inlineHosts));
    }

    private function baseConfig(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowStaticElements()
            ->allowAttribute('style', '*')
            ->allowLinkSchemes([
                'http',
                'https',
                'mailto',
                'tel',
            ])
            ->withMaxInputLength(5_000_000);
    }
}
