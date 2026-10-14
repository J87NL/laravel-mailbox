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
            '/<img\b[^>]*>/i',
            function (array $match) use ($source, &$wanted): string {
                $contentId = $this->sourceContentId($match[0], $source);

                if (is_null($contentId)) {
                    return $this->keepsOwnSource($match[0]) ? $match[0] : '';
                }

                $wanted[] = [
                    'content_id' => $contentId,
                    'tag' => $match[0],
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
     * @param  array<int, array{content_id: string, tag: string}>  $wanted
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
     * @param  array{content_id: string, tag: string}  $image
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

        $tag = (string) preg_replace('/\s'.preg_quote(MessageHtml::CID_ATTRIBUTE, '/').'\s*=\s*(["\']).*?\1/i', '', $image['tag']);

        return (string) preg_replace('/\bsrc\s*=\s*(["\']).*?\1/i', sprintf('src="cid:%s"', $name), $tag, 1);
    }

    private function sourceContentId(string $tag, ?MailboxMessage $source): ?string
    {
        $marked = $this->attribute($tag, MessageHtml::CID_ATTRIBUTE);
        $parsed = $this->urls->parse((string) $this->attribute($tag, 'src'));

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

    private function keepsOwnSource(string $tag): bool
    {
        $source = strtolower(trim((string) $this->attribute($tag, 'src')));

        return $source !== '' && ! str_starts_with($source, 'cid:');
    }

    private function attribute(string $tag, string $name): ?string
    {
        if (preg_match(sprintf('/\s%s\s*=\s*(["\'])(.*?)\1/is', preg_quote($name, '/')), $tag, $match) !== 1) {
            return null;
        }

        return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
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
