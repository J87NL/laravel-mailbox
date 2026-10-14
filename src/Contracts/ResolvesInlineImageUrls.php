<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Contracts;

interface ResolvesInlineImageUrls
{
    /**
     * @return array{message_id: int, content_id: string}|null
     */
    public function parse(string $url): ?array;
}
