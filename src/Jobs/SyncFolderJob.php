<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Sync\SyncFolderMessages;

class SyncFolderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $folderId,
    ) {
        $this->onQueue(config('mailbox.sync.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->folderId;
    }

    public function handle(MailboxConnector $connector, SyncFolderMessages $sync): void
    {
        $folder = MailboxFolder::query()
            ->with('mailbox')
            ->find($this->folderId);

        if (is_null($folder) || ! $folder->mailbox->active) {
            return;
        }

        try {
            $sync->handle($folder);
        } finally {
            $connector->disconnect($folder->mailbox);
        }
    }
}
