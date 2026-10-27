<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\Exceptions\ImapParserException;
use DirectoryTree\ImapEngine\Exceptions\ImapStreamException;
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
            $this->connector->readUntil($mailbox, $budget->readDeadline());

            $failures = $this->syncInRotation($this->syncFolders->handle($mailbox), $budget);

            $mailbox->update([
                'last_error' => $this->describeFailures($failures),
                'synced_at' => now(),
            ]);

            $budget->reportDeferred([
                'mailbox_id' => $mailbox->id,
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

        $stopped = false;

        $folders
            ->filter(fn (MailboxFolder $folder): bool => $folder->selectable && $folder->skipsSync())
            ->each(fn (MailboxFolder $folder) => rescue(fn () => $this->syncFolderMessages->handle($folder)));

        foreach ($this->inRotation($folders) as $position => $folder) {
            if ($stopped || ($position > 0 && $budget->exhausted())) {
                $stopped = true;
                $budget->defer($folder, SyncBudget::NOT_REACHED);

                continue;
            }

            try {
                $this->syncFolderMessages->handle($folder, $budget);
            } catch (ImapConnectionException|ImapParserException|ImapStreamException $exception) {
                report($exception);
                $failures[$folder->path] = $exception->getMessage();
                $stopped = true;
            } catch (Throwable $exception) {
                report($exception);
                $failures[$folder->path] = $exception->getMessage();
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
            ->filter(fn (MailboxFolder $folder): bool => $folder->selectable && ! $folder->skipsSync())
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
