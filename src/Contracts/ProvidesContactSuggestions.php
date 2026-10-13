<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Contracts;

use J87NL\Mailbox\Models\Mailbox;

interface ProvidesContactSuggestions
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function suggestions(Mailbox $mailbox, string $query, int $limit): array;
}
