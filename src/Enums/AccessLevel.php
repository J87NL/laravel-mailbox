<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Enums;

enum AccessLevel: string
{
    case Manage = 'manage';
    case Read = 'read';
    case Write = 'write';

    public static function highest(array $levels): ?self
    {
        return collect($levels)
            ->sortByDesc(fn (self $level): int => $level->rank())
            ->first();
    }

    public function allows(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    public function label(): string
    {
        return __('mailbox::mailbox.level_'.$this->value);
    }

    public function rank(): int
    {
        return match ($this) {
            self::Read => 1,
            self::Write => 2,
            self::Manage => 3,
        };
    }
}
