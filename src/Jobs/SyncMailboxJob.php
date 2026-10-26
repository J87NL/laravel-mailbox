<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Sync\SyncBudget;
use J87NL\Mailbox\Sync\SyncMailbox;

class SyncMailboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const DEFAULT_TIMEOUT = 55;

    public int $tries = 1;

    public int $timeout = self::DEFAULT_TIMEOUT;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $mailboxId,
    ) {
        $this->onQueue(config('mailbox.sync.queue'));
        $this->timeout = (int) config('mailbox.sync.job_timeout_seconds', self::DEFAULT_TIMEOUT);
    }

    public function uniqueId(): string
    {
        return (string) $this->mailboxId;
    }

    public function handle(SyncMailbox $sync): void
    {
        $mailbox = Mailbox::query()
            ->find($this->mailboxId);

        if (! $mailbox?->active) {
            return;
        }

        $sync->handle($mailbox, SyncBudget::fromConfig());
    }
}
