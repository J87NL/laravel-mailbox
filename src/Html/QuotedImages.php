<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Html;

use DirectoryTree\ImapEngine\Attachment;
use Illuminate\Support\Str;
use J87NL\Mailbox\Actions\FetchMessageBody;
use J87NL\Mailbox\Contracts\ResolvesInlineImageUrls;
use J87NL\Mailbox\Exceptions\MessageGoneException;
use J87NL\Mailbox\Models\MailboxMessage;
use Symfony\Component\Mime\Email;

class QuotedImages
{
    private const MARKER = '<!--mailbox-quoted-image:%d-->';

    public function __construct(
        private readonly FetchMessageBody $fetch,
        private readonly ResolvesInlineImageUrls $urls,
    ) {}

    public function embed(Email $email, string $html, ?MailboxMessage $source): string
    {
        $wanted = [];

        $marked = preg_replace_callback(
            '/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i',
            function (array $match) use ($source, &$wanted): string {
                $attributes = $this->attributes($match[0]);
                $contentId = $this->sourceContentId($attributes, $source);

                if (is_null($contentId)) {
                    return $this->keepsOwnSource($attributes) ? $match[0] : '';
                }

                if ($contentId === '') {
                    return '';
                }

                $wanted[] = [
                    'attributes' => $attributes,
                    'content_id' => $contentId,
                ];

                return sprintf(self::MARKER, count($wanted) - 1);
            },
            $html,
        ) ?? $html;

        if ($wanted === [] || is_null($source)) {
            return $marked;
        }

        return $this->replaceMarkers($email, $marked, $wanted, $this->inlineParts($source));
    }

    /**
     * @param  array<int, array{attributes: array<string, string>, content_id: string}>  $wanted
     * @param  array<string, Attachment>  $parts
     */
    private function replaceMarkers(Email $email, string $html, array $wanted, array $parts): string
    {
        foreach ($wanted as $index => $image) {
            $html = str_replace(sprintf(self::MARKER, $index), $this->embedded($email, $image, $parts), $html);
        }

        return $html;
    }

    /**
     * @param  array{attributes: array<string, string>, content_id: string}  $image
     * @param  array<string, Attachment>  $parts
     */
    private function embedded(Email $email, array $image, array $parts): string
    {
        $part = $parts[$image['content_id']] ?? null;

        if (is_null($part)) {
            return '';
        }

        $name = sprintf('quoted-%s', Str::lower(Str::random(16)));

        $email->embed($part->contents(), $name, $part->contentType());

        $attributes = [
            ...array_diff_key($image['attributes'], array_flip([
                MessageHtml::CID_ATTRIBUTE,
                'src',
                'srcset',
            ])),
            'src' => sprintf('cid:%s', $name),
        ];

        return sprintf('<img%s>', implode('', array_map(
            fn (string $attribute, string $value): string => sprintf(' %s="%s"', $attribute, e($value)),
            array_keys($attributes),
            $attributes,
        )));
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function sourceContentId(array $attributes, ?MailboxMessage $source): ?string
    {
        $marked = $attributes[MessageHtml::CID_ATTRIBUTE] ?? null;
        $parsed = $this->urls->parse($attributes['src'] ?? '');

        if (is_null($marked) && is_null($parsed)) {
            return null;
        }

        if (is_null($source) || (! is_null($parsed) && $parsed['message_id'] !== $source->id)) {
            return '';
        }

        if (! is_null($marked)) {
            return $marked;
        }

        return $parsed['content_id'];
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function keepsOwnSource(array $attributes): bool
    {
        $source = strtolower(trim($attributes['src'] ?? ''));

        return $source !== '' && ! str_starts_with($source, 'cid:');
    }

    /**
     * @return array<string, string>
     */
    private function attributes(string $tag): array
    {
        preg_match_all(
            '/([^\s"\'=<>\/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/',
            (string) preg_replace('/^<img\b|\/?>$/i', '', $tag),
            $matches,
            PREG_SET_ORDER,
        );

        $attributes = [];

        foreach ($matches as $match) {
            $name = strtolower($match[1]);

            if (array_key_exists($name, $attributes)) {
                continue;
            }

            $attributes[$name] = html_entity_decode(($match[2] ?? '').($match[3] ?? '').($match[4] ?? ''), ENT_QUOTES | ENT_HTML5);
        }

        return $attributes;
    }

    /**
     * @return array<string, Attachment>
     */
    private function inlineParts(MailboxMessage $source): array
    {
        try {
            return $this->fetch->inlineAttachments($source->loadMissing('folder'));
        } catch (MessageGoneException $exception) {
            report($exception);

            return [];
        }
    }
}
