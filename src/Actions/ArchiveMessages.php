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
use J87NL\Mailbox\Support\RawMessageCache;

class ArchiveMessages
{
    public const GMAIL_INBOX_LABEL = '\\Inbox';

    public function __construct(
        private readonly EnsureFolder $ensureFolder,
        private readonly MoveMessages $moveMessages,
        private readonly MailboxConnector $connector,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function handle(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): int
    {
        if ($messages->isEmpty()) {
            return 0;
        }

        [$inInbox, $elsewhere] = $messages->partition(
            fn (MailboxMessage $message): bool => $message->folder->role === FolderRole::Inbox,
        );

        if ($inInbox->isEmpty() || ! $this->connector->supportsGmailLabels($mailbox)) {
            return $this->move($mailbox, $messages, $user);
        }

        $archived = $this->removeInboxLabel($mailbox, $inInbox, $user);

        if ($elsewhere->isEmpty()) {
            return $archived;
        }

        return $archived + $this->move($mailbox, $elsewhere, $user);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    private function move(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): int
    {
        $archive = $this->ensureFolder->handle($mailbox, FolderRole::Archive, (string) config('mailbox.folders.archive_fallback', 'Archive'));

        return $this->moveMessages->handle($messages, $archive, $user, AuditAction::Archived);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    private function removeInboxLabel(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): int
    {
        $allMail = $mailbox->folderWithRole(FolderRole::Archive)?->path;

        return $messages
            ->groupBy('mailbox_folder_id')
            ->sum(function (Collection $group) use ($allMail, $user): int {
                $uids = $group
                    ->pluck('uid')
                    ->map(fn (mixed $uid): int => (int) $uid)
                    ->values()
                    ->all();

                $this->connector->removeGmailLabel($group->first()->folder, $uids, self::GMAIL_INBOX_LABEL);

                $this->audit->forMessages($group, AuditAction::Archived, $user, $allMail);
                $this->raw()->forget($group);

                MailboxMessage::query()
                    ->whereKey($group->pluck('id')->all())
                    ->delete();

                return $group->count();
            });
    }

    private function raw(): RawMessageCache
    {
        return app(RawMessageCache::class);
    }
}
