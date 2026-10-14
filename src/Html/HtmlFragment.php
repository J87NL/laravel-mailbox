<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Html;

use Dom\Element;
use Dom\HTMLDocument;

class HtmlFragment
{
    public static function parse(string $html): Element
    {
        $document = HTMLDocument::createFromString(
            sprintf('<!DOCTYPE html><html><head></head><body>%s</body></html>', $html),
            LIBXML_NOERROR,
        );

        return $document->body ?? $document->createElement('body');
    }

    /**
     * @return list<Element>
     */
    public static function elements(Element $body, string $selector): array
    {
        return iterator_to_array($body->querySelectorAll($selector), false);
    }

    public static function serialize(Element $body): string
    {
        return $body->innerHTML;
    }
}
