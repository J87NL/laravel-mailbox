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

    public function label(string $type, int|string $id): ?string;
}
