<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Html;

use Closure;
use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class MessageHtml
{
    private const REMOTE_REFERENCE = '/(<img[^>]+src|background)\s*=\s*["\']?\s*https?:|srcset\s*=\s*["\']?[^"\'>]*(https?:)?\/\/|url\(\s*["\']?\s*(https?:)?\/\//i';

    public const CID_ATTRIBUTE = 'data-mailbox-cid';

    private const INLINE_PLACEHOLDER = 'https://mailbox-inline.invalid/';

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

        $tokens = [];

        $config = $this->baseConfig()
            ->allowMediaSchemes([
                'data',
                'https',
            ])
            ->dropAttribute('srcset', '*');

        $clean = (new HtmlSanitizer($config))->sanitize($this->tokeniseInlineImages($html, $tokens));

        return $this->restoreInlineImages($this->stripResourceLoadingStyles($clean), $tokens, $cidUrl);
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

    /**
     * @param  array<string, string>  $tokens
     */
    private function tokeniseInlineImages(string $html, array &$tokens): string
    {
        $body = HtmlFragment::parse($html);

        foreach (HtmlFragment::elements($body, sprintf('[%s]', self::CID_ATTRIBUTE)) as $element) {
            $element->removeAttribute(self::CID_ATTRIBUTE);
        }

        foreach (HtmlFragment::elements($body, 'img[src]') as $image) {
            $source = trim((string) $image->getAttribute('src'));

            if (! str_starts_with(strtolower($source), 'cid:')) {
                continue;
            }

            $token = sprintf('%s%s', self::INLINE_PLACEHOLDER, Str::random(40));
            $tokens[$token] = substr($source, 4);
            $image->setAttribute('src', $token);
        }

        return HtmlFragment::serialize($body);
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function restoreInlineImages(string $html, array $tokens, Closure $cidUrl): string
    {
        $body = HtmlFragment::parse($html);

        foreach (HtmlFragment::elements($body, sprintf('[%s]', self::CID_ATTRIBUTE)) as $element) {
            $element->removeAttribute(self::CID_ATTRIBUTE);
        }

        foreach (HtmlFragment::elements($body, 'img[src]') as $image) {
            $contentId = $tokens[(string) $image->getAttribute('src')] ?? null;

            if (is_null($contentId)) {
                continue;
            }

            $image->setAttribute('src', (string) $cidUrl($contentId));
            $image->setAttribute(self::CID_ATTRIBUTE, $contentId);
        }

        return HtmlFragment::serialize($body);
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
