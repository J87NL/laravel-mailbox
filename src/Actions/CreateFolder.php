<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;

class CreateFolder
{
    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Mailbox $mailbox, string $name, ?MailboxFolder $parent, ?Authenticatable $user): MailboxFolder
    {
        $path = $parent ? $parent->path.$parent->delimiter.$name : $name;

        $remote = $this->connector->imap($mailbox)->folders()->create($path);

        $this->audit->forMailbox($mailbox, AuditAction::FolderCreated, $user, targetPath: $path);

        return MailboxFolder::query()->create([
            'delimiter' => $remote->delimiter(),
            'flags' => $remote->flags(),
            'mailbox_id' => $mailbox->id,
            'name' => $remote->name(),
            'path' => $remote->path(),
            'role' => FolderRole::Custom,
            'selectable' => true,
        ]);
    }
}
