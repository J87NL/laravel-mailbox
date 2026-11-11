<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use InvalidArgumentException;

final class SpamVerdict
{
    public function __construct(
        public readonly ?float $score,
        public readonly ?string $reason = null,
    ) {
        if (is_null($score) || ($score >= 0.0 && $score <= 1.0)) {
            return;
        }

        throw new InvalidArgumentException(sprintf('A spam score runs from 0 to 1, %s given.', $score));
    }

    public static function none(?string $reason = null): self
    {
        return new self(null, $reason);
    }

    public function hasScore(): bool
    {
        return ! is_null($this->score);
    }
}
