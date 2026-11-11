<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use Illuminate\Support\Facades\Log;

final class SpamThresholds
{
    public const DEFAULT_MOVE = 0.95;

    public const DEFAULT_SUGGEST = 0.80;

    private function __construct(
        public readonly float $suggest,
        public readonly float $move,
    ) {}

    public static function fromConfig(): self
    {
        $suggest = self::valid('suggest_threshold', self::DEFAULT_SUGGEST);
        $move = self::valid('move_threshold', self::DEFAULT_MOVE);

        if ($move >= $suggest) {
            return new self($suggest, $move);
        }

        Log::warning('The spam move threshold is below the suggest threshold, the spam filter uses the default thresholds.', [
            'move_threshold' => $move,
            'suggest_threshold' => $suggest,
        ]);

        return new self(self::DEFAULT_SUGGEST, self::DEFAULT_MOVE);
    }

    private static function valid(string $key, float $default): float
    {
        $value = config(sprintf('mailbox.spam.%s', $key));

        if (is_numeric($value) && (float) $value > 0.0 && (float) $value <= 1.0) {
            return (float) $value;
        }

        Log::warning('A spam threshold is missing or outside 0 to 1, the spam filter uses its default.', [
            'default' => $default,
            'key' => sprintf('mailbox.spam.%s', $key),
        ]);

        return $default;
    }
}
