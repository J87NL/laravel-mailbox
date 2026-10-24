<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface ResolvesAccessors
{
    /**
     * @return array<int, array{type: string, id: int|string}>
     */
    public function accessorsFor(Authenticatable $user): array;

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function assignable(): array;

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function search(string $term, int $limit): array;

    /**
     * @param  array<int, array{type: string, id: int|string}>  $accessors
     * @return array<string, string>
     */
    public function labels(array $accessors): array;
}
