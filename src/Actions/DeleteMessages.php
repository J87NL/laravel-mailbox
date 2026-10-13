<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxMessage;

class DeleteMessages
{
    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly EnsureFolder $ensureFolder,
        private readonly MoveMessages $moveMessages,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function handle(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): int
    {
        $trash = $this->ensureFolder->handle($mailbox, FolderRole::Trash, (string) config('mailbox.folders.trash_fallback'));

        [$inTrash, $elsewhere] = $messages->partition(
            fn (MailboxMessage $message): bool => $message->mailbox_folder_id === $trash->id,
        );

        $trashed = $elsewhere->isEmpty()
            ? 0
            : $this->moveMessages->handle($elsewhere, $trash, $user, AuditAction::Trashed);

        return $trashed + $this->purge($inTrash, $user);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    private function purge(Collection $messages, ?Authenticatable $user): int
    {
        if ($messages->isEmpty()) {
            return 0;
        }

        $folder = $messages->first()->folder;
        $uids = $messages->pluck('uid')->all();

        $this->connector->folder($folder)
            ->messages()
            ->destroy($uids);

        $this->connector->expunge($folder, $uids);

        $this->audit->forMessages($messages, AuditAction::Deleted, $user);

        MailboxMessage::query()
            ->whereKey($messages->pluck('id')->all())
            ->delete();

        return $messages->count();
    }
}
