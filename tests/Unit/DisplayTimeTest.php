<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use J87NL\Mailbox\Support\DisplayTime;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('formats stored UTC dates in the display timezone', function (?string $displayTimezone, string $expected): void {
    config()->set('app.timezone', 'UTC');
    config()->set('mailbox.display_timezone', $displayTimezone);

    expect(DisplayTime::format(Carbon::parse('2026-10-13 22:30:00', 'UTC'), 'd-m-Y H:i'))->toBe($expected);
})->with([
    'display timezone' => ['Europe/Amsterdam', '14-10-2026 00:30'],
    'app timezone fallback' => [null, '13-10-2026 22:30'],
    'invalid timezone falls back' => ['Mars/Olympus', '13-10-2026 22:30'],
]);

it('decides today in the display timezone', function (): void {
    config()->set('mailbox.display_timezone', 'Europe/Amsterdam');
    Carbon::setTestNow(Carbon::parse('2026-10-13 22:30:00', 'UTC'));

    expect(DisplayTime::isToday(Carbon::parse('2026-10-13 21:00:00', 'UTC')))->toBeFalse()
        ->and(DisplayTime::isToday(Carbon::parse('2026-10-13 22:10:00', 'UTC')))->toBeTrue();
});
