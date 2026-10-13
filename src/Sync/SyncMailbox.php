<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use DirectoryTree\ImapEngine\Support\Str;
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
            $failures = $this->syncFolders
                ->handle($mailbox)
                ->mapWithKeys(fn (MailboxFolder $folder): array => [$folder->path => $this->syncFolder($folder)])
                ->filter()
                ->all();

            $mailbox->update([
                'last_error' => $this->describeFailures($failures),
                'synced_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $mailbox->update(['last_error' => $exception->getMessage()]);

            throw $exception;
        } finally {
            $this->connector->disconnect($mailbox);
        }
    }

    private function syncFolder(MailboxFolder $folder): ?string
    {
        try {
            $this->syncFolderMessages->handle($folder);

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return $exception->getMessage();
        }
    }

    /**
     * @param  array<string, string>  $failures
     */
    private function describeFailures(array $failures): ?string
    {
        if ($failures === []) {
            return null;
        }

        return collect($failures)
            ->map(fn (string $message, string $path): string => sprintf('%s: %s', Str::fromImapUtf7($path), $message))
            ->implode(PHP_EOL);
    }
}
