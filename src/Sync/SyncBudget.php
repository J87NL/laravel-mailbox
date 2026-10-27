<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use Carbon\CarbonInterface;
use Closure;
use DirectoryTree\ImapEngine\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use J87NL\Mailbox\Jobs\SyncMailboxJob;
use J87NL\Mailbox\Models\MailboxFolder;

final class SyncBudget
{
    public const DEFAULT_SECONDS = 40;

    public const DEFAULT_STEP_RESERVE_SECONDS = 5;

    public const JOB_TIMEOUT_MARGIN_SECONDS = 5;

    public const NOT_REACHED = 'not reached';

    private bool $exhausted = false;

    private int $steps = 0;

    /**
     * @var array<string, string>
     */
    private array $deferred = [];

    private function __construct(
        private readonly ?CarbonInterface $deadline,
        private readonly int $seconds = 0,
        private readonly ?CarbonInterface $readDeadline = null,
    ) {}

    public static function seconds(int $seconds, ?int $jobTimeoutSeconds = null): self
    {
        $seconds = max($seconds, 1);

        $readDeadline = is_null($jobTimeoutSeconds)
            ? null
            : now()->addSeconds(max($jobTimeoutSeconds - self::JOB_TIMEOUT_MARGIN_SECONDS, 1));

        return new self(now()->addSeconds($seconds), $seconds, $readDeadline);
    }

    public static function fromConfig(): self
    {
        return self::seconds(
            (int) config('mailbox.sync.time_budget_seconds', self::DEFAULT_SECONDS),
            (int) config('mailbox.sync.job_timeout_seconds', SyncMailboxJob::DEFAULT_TIMEOUT),
        );
    }

    public function readDeadline(): ?CarbonInterface
    {
        return $this->readDeadline;
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

    public function remaining(): float
    {
        if (is_null($this->deadline)) {
            return INF;
        }

        return max(0.0, -1 * (float) $this->deadline->diffInMilliseconds(now(), false) / 1000);
    }

    public function allows(MailboxFolder $folder, string $step): bool
    {
        if (! $this->isLimited() || $this->steps === 0) {
            return true;
        }

        if ($this->exhausted()) {
            return false;
        }

        return $this->remaining() > $this->estimate($folder, $step);
    }

    public function estimate(MailboxFolder $folder, string $step): float
    {
        $measured = (float) rescue(fn (): mixed => Cache::get($this->durationKey($folder, $step), 0), 0, report: false);

        if ($this->seconds > 0) {
            $measured = min($measured, $this->seconds / 2);
        }

        return max(
            $measured,
            (float) config('mailbox.sync.step_reserve_seconds', self::DEFAULT_STEP_RESERVE_SECONDS),
        );
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function measure(MailboxFolder $folder, string $step, Closure $callback): mixed
    {
        $started = now();

        try {
            return $callback();
        } finally {
            $this->record($folder, $step, $started);
        }
    }

    public function record(MailboxFolder $folder, string $step, CarbonInterface $started): void
    {
        $this->steps++;

        $seconds = round(abs((float) $started->diffInMilliseconds(now())) / 1000, 3);

        rescue(fn (): bool => Cache::put($this->durationKey($folder, $step), $seconds, now()->addDay()), false, report: false);
    }

    public function defer(MailboxFolder $folder, string $step): void
    {
        $this->deferred[Str::fromImapUtf7($folder->path)] = $step;
    }

    /**
     * @return array<string, string>
     */
    public function deferred(): array
    {
        return $this->deferred;
    }

    /**
     * @param  array<string, int|string>  $context
     */
    public function reportDeferred(array $context): void
    {
        if ($this->deferred === []) {
            return;
        }

        Log::info('Mailbox sync deferred work to the next run', [
            ...$context,
            'deferred' => $this->deferred,
        ]);
    }

    private function durationKey(MailboxFolder $folder, string $step): string
    {
        return sprintf('mailbox-sync-duration:%d:%s', $folder->id, $step);
    }
}
