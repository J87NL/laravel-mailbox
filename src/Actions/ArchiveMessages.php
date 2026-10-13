<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;

class ArchiveMessages
{
    public function __construct(
        private readonly EnsureFolder $ensureFolder,
        private readonly MoveMessages $moveMessages,
    ) {}

    public function handle(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): int
    {
        $archive = $this->ensureFolder->handle($mailbox, FolderRole::Archive, config('mailbox.folders.archive_fallback'));

        return $this->moveMessages->handle($messages, $archive, $user, AuditAction::Archived);
    }
}
