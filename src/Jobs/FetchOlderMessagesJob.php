<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Jobs;

use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use J87NL\Mailbox\Actions\FetchOlderMessages;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Sync\SyncBudget;
use Throwable;

class FetchOlderMessagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const RELEASE_SECONDS = 30;

    public int $tries = 3;

    public int $timeout = SyncFolderJob::DEFAULT_TIMEOUT;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $folderId,
    ) {
        $this->onQueue(config('mailbox.sync.queue'));
        $this->timeout = (int) config('mailbox.sync.job_timeout_seconds', SyncFolderJob::DEFAULT_TIMEOUT);
    }

    public function uniqueId(): string
    {
        return (string) $this->folderId;
    }

    public function handle(MailboxConnector $connector, FetchOlderMessages $fetch): void
    {
        $folder = MailboxFolder::query()
            ->with('mailbox')
            ->find($this->folderId);

        if (is_null($folder) || ! $folder->mailbox->active) {
            return;
        }

        try {
            $connector->readUntil($folder->mailbox, SyncBudget::fromConfig()->readDeadline());

            if (is_null($fetch->handle($folder))) {
                $this->release(self::RELEASE_SECONDS);
            }
        } catch (ImapConnectionTimedOutException $exception) {
            $this->recordError($folder, $exception);
        } finally {
            $connector->disconnect($folder->mailbox);
        }
    }

    public function failed(Throwable $exception): void
    {
        $folder = MailboxFolder::query()
            ->with('mailbox')
            ->find($this->folderId);

        if (is_null($folder)) {
            return;
        }

        $this->recordError($folder, $exception);
    }

    private function recordError(MailboxFolder $folder, Throwable $exception): void
    {
        $folder->mailbox->update(['last_error' => sprintf('%s: %s', $folder->path, $exception->getMessage())]);
    }
}
