<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;

class EnsureFolder
{
    public function __construct(
        private readonly MailboxConnector $connector,
    ) {}

    public function handle(Mailbox $mailbox, FolderRole $role, string $fallbackPath): MailboxFolder
    {
        $folder = $mailbox->folderWithRole($role)
            ?? $mailbox->folders()->where('path', $fallbackPath)->first();

        if ($folder) {
            return $folder;
        }

        $remote = $this->connector->imap($mailbox)->folders()->firstOrCreate($fallbackPath);

        return MailboxFolder::query()
            ->create([
                'delimiter' => $remote->delimiter(),
                'flags' => $remote->flags(),
                'mailbox_id' => $mailbox->id,
                'name' => $remote->name(),
                'path' => $remote->path(),
                'role' => $role,
                'selectable' => true,
            ]);
    }
}
