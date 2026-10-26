<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use DirectoryTree\ImapEngine\Support\Str;
use Illuminate\Support\Collection;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\FolderRole;
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

    public function handle(Mailbox $mailbox, ?SyncBudget $budget = null): void
    {
        $budget ??= SyncBudget::unlimited();

        try {
            $failures = $this->syncInRotation($this->syncFolders->handle($mailbox), $budget);

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

    /**
     * @param  Collection<int, MailboxFolder>  $folders
     * @return array<string, string>
     */
    private function syncInRotation(Collection $folders, SyncBudget $budget): array
    {
        $failures = [];

        foreach ($this->inRotation($folders) as $position => $folder) {
            if ($position > 0 && $budget->exhausted()) {
                break;
            }

            $failure = $this->syncFolder($folder, $budget);

            if (! is_null($failure)) {
                $failures[$folder->path] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @param  Collection<int, MailboxFolder>  $folders
     * @return Collection<int, MailboxFolder>
     */
    private function inRotation(Collection $folders): Collection
    {
        return $folders
            ->sortBy(fn (MailboxFolder $folder): array => [
                $this->rotationGroup($folder),
                $folder->synced_at?->getTimestamp() ?? 0,
                $folder->id,
            ])
            ->values();
    }

    private function rotationGroup(MailboxFolder $folder): int
    {
        if ($folder->role === FolderRole::Inbox) {
            return 0;
        }

        if (! is_null($folder->synced_at)) {
            return 1;
        }

        return 2;
    }

    private function syncFolder(MailboxFolder $folder, SyncBudget $budget): ?string
    {
        try {
            $this->syncFolderMessages->handle($folder, $budget);

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
