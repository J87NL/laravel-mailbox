<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use Carbon\CarbonInterface;

final class SyncBudget
{
    public const DEFAULT_SECONDS = 40;

    private bool $exhausted = false;

    private function __construct(
        private readonly ?CarbonInterface $deadline,
    ) {}

    public static function seconds(int $seconds): self
    {
        return new self(now()->addSeconds(max($seconds, 1)));
    }

    public static function fromConfig(): self
    {
        return self::seconds((int) config('mailbox.sync.time_budget_seconds', self::DEFAULT_SECONDS));
    }

    public static function unlimited(): self
    {
        return new self(null);
    }

    public function isLimited(): bool
    {
        return ! is_null($this->deadline);
    }

    public function exhausted(): bool
    {
        if ($this->exhausted || is_null($this->deadline)) {
            return $this->exhausted;
        }

        $this->exhausted = now()->greaterThanOrEqualTo($this->deadline);

        return $this->exhausted;
    }
}
