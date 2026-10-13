<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;
use Throwable;

class SyncMailbox
{
    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly SyncFolderMessages $syncFolderMessages,
        private readonly SyncFolders $syncFolders,
    ) {}

    public function handle(Mailbox $mailbox): void
    {
        try {
            $this->syncFolders
                ->handle($mailbox)
                ->each(fn (MailboxFolder $folder) => $this->syncFolderMessages->handle($folder));

            $mailbox->update(['last_error' => null, 'synced_at' => now()]);
        } catch (Throwable $exception) {
            $mailbox->update(['last_error' => $exception->getMessage()]);

            throw $exception;
        } finally {
            $this->connector->disconnect($mailbox);
        }
    }
}
