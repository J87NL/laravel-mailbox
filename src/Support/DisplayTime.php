<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Support;

use Carbon\CarbonInterface;

class DisplayTime
{
    public static function timezone(): string
    {
        $timezone = config('mailbox.display_timezone');

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }

    public static function local(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->setTimezone(self::timezone());
    }

    public static function format(?CarbonInterface $date, string $format): string
    {
        if (is_null($date)) {
            return '';
        }

        return self::local($date)->format($format);
    }

    public static function isToday(CarbonInterface $date): bool
    {
        return self::local($date)->isSameDay(now(self::timezone()));
    }
}
