<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Html;

use DirectoryTree\ImapEngine\Attachment;
use Dom\Element;
use Dom\Text;
use Dom\XPath;
use Illuminate\Support\Str;
use J87NL\Mailbox\Actions\FetchMessageBody;
use J87NL\Mailbox\Contracts\ResolvesInlineImageUrls;
use J87NL\Mailbox\Exceptions\MessageGoneException;
use J87NL\Mailbox\Models\MailboxMessage;
use Symfony\Component\Mime\Email;

class QuotedImages
{
    public function __construct(
        private readonly FetchMessageBody $fetch,
        private readonly ResolvesInlineImageUrls $urls,
    ) {}

    public function embed(Email $email, string $html, ?MailboxMessage $source): string
    {
        $body = HtmlFragment::parse($html);
        $wanted = [];

        foreach (HtmlFragment::elements($body, 'img') as $image) {
            $contentId = $this->sourceContentId($image, $source);

            if (is_null($contentId)) {
                $this->keepOrRemove($image);

                continue;
            }

            if ($contentId === '') {
                $image->remove();

                continue;
            }

            $wanted[] = [
                'content_id' => $contentId,
                'image' => $image,
            ];
        }

        $this->embedWanted($email, $wanted, $source);
        $this->removeInlineUrls($body);

        return HtmlFragment::serialize($body);
    }

    /**
     * @param  array<int, array{content_id: string, image: Element}>  $wanted
     */
    private function embedWanted(Email $email, array $wanted, ?MailboxMessage $source): void
    {
        if ($wanted === [] || is_null($source)) {
            return;
        }

        $parts = $this->inlineParts($source);

        foreach ($wanted as $item) {
            $this->embedImage($email, $item['image'], $parts[$item['content_id']] ?? null);
        }
    }

    private function embedImage(Email $email, Element $image, ?Attachment $part): void
    {
        if (is_null($part)) {
            $image->remove();

            return;
        }

        $name = sprintf('quoted-%s', Str::lower(Str::random(16)));

        $email->embed($part->contents(), $name, $part->contentType());

        $image->removeAttribute(MessageHtml::CID_ATTRIBUTE);
        $image->removeAttribute('srcset');
        $image->setAttribute('src', sprintf('cid:%s', $name));
    }

    private function sourceContentId(Element $image, ?MailboxMessage $source): ?string
    {
        $marked = $image->getAttribute(MessageHtml::CID_ATTRIBUTE);
        $parsed = $this->urls->parse((string) $image->getAttribute('src'));

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

    private function keepOrRemove(Element $image): void
    {
        $source = strtolower(trim((string) $image->getAttribute('src')));

        if ($source !== '' && ! str_starts_with($source, 'cid:')) {
            return;
        }

        $image->remove();
    }

    private function removeInlineUrls(Element $body): void
    {
        $prefix = $this->urls->urlPrefix();

        if (is_null($prefix) || $prefix === '') {
            return;
        }

        foreach (HtmlFragment::elements($body, '*') as $element) {
            $this->removeInlineUrlAttributes($element, $prefix);
        }

        foreach ($this->textNodes($body) as $text) {
            $text->data = (string) preg_replace(sprintf('#%s\S*#i', preg_quote($prefix, '#')), '', $text->data);
        }
    }

    private function removeInlineUrlAttributes(Element $element, string $prefix): void
    {
        foreach ($element->getAttributeNames() as $name) {
            if (str_contains(strtolower((string) $element->getAttribute($name)), strtolower($prefix))) {
                $element->removeAttribute($name);
            }
        }
    }

    /**
     * @return list<Text>
     */
    private function textNodes(Element $body): array
    {
        $texts = [];

        foreach ((new XPath($body->ownerDocument))->query('.//text()', $body) as $node) {
            if ($node instanceof Text) {
                $texts[] = $node;
            }
        }

        return $texts;
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
