<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use DirectoryTree\ImapEngine\FolderInterface;
use Illuminate\Support\Collection;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;
use Throwable;

class SyncFolders
{
    public function __construct(
        private readonly MailboxConnector $connector,
    ) {}

    public function bootstrap(Mailbox $mailbox): void
    {
        if ($mailbox->folders()->exists()) {
            return;
        }

        try {
            $this->handle($mailbox);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $this->connector->disconnect($mailbox);
        }
    }

    /**
     * @return Collection<int, MailboxFolder>
     */
    public function handle(Mailbox $mailbox): Collection
    {
        $remote = $this->connector->imap($mailbox)->folders()->get();

        $folders = collect($remote)->map(fn (FolderInterface $folder): MailboxFolder => $this->upsert($mailbox, $folder));

        $mailbox->folders()
            ->whereNotIn('id', $folders->pluck('id'))
            ->delete();

        return $folders;
    }

    private function upsert(Mailbox $mailbox, FolderInterface $folder): MailboxFolder
    {
        $flags = $folder->flags();

        return MailboxFolder::query()
            ->updateOrCreate(
                [
                    'mailbox_id' => $mailbox->id,
                    'path' => $folder->path(),
                ],
                [
                    'delimiter' => $folder->delimiter(),
                    'flags' => $flags,
                    'name' => $folder->name(),
                    'role' => FolderRole::fromImap($folder->path(), $flags),
                    'selectable' => ! in_array('\\Noselect', $flags, true) && ! in_array('\\NonExistent', $flags, true),
                ],
            );
    }
}
