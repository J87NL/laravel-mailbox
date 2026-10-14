<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Html;

use J87NL\Mailbox\Contracts\ResolvesInlineImageUrls;

class NoInlineImageUrls implements ResolvesInlineImageUrls
{
    public function parse(string $url): ?array
    {
        return null;
    }
}
