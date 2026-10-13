<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use Illuminate\Database\Eloquent\Collection;
use J87NL\Mailbox\Actions\RecordAudit;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;

class AnnounceReceivedMessages
{
    private const SILENT_ROLES = [
        FolderRole::Drafts,
        FolderRole::Junk,
        FolderRole::Sent,
        FolderRole::Trash,
    ];

    public function __construct(
        private readonly RecordAudit $audit,
    ) {}

    public function watches(MailboxFolder $folder): bool
    {
        return ! in_array($folder->role, self::SILENT_ROLES, true);
    }

    /**
     * @param  array<int, int>  $uids
     */
    public function handle(MailboxFolder $folder, array $uids, bool $announce = true): void
    {
        if ($uids === []) {
            return;
        }

        $stored = $folder->messages()
            ->whereIn('uid', $uids)
            ->orderBy('uid')
            ->get();

        $stored->each(fn (MailboxMessage $message): MailboxMessage => $message->setRelation('folder', $folder));

        $received = $this->unknown($folder, $stored);

        $this->audit->forMessages($received, AuditAction::Received, null);

        if (! $announce) {
            return;
        }

        $received->each(function (MailboxMessage $message): void {
            MessageReceived::dispatch($message);
        });
    }

    /**
     * @param  Collection<int, MailboxMessage>  $stored
     * @return Collection<int, MailboxMessage>
     */
    private function unknown(MailboxFolder $folder, Collection $stored): Collection
    {
        $messageIds = $stored
            ->pluck('message_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $known = MailboxMessage::query()
            ->where('mailbox_id', $folder->mailbox_id)
            ->whereIn('message_id', $messageIds)
            ->whereKeyNot($stored->modelKeys())
            ->pluck('message_id')
            ->merge(MailboxAuditEntry::query()
                ->where('mailbox_id', $folder->mailbox_id)
                ->whereIn('message_id', $messageIds)
                ->pluck('message_id'))
            ->all();

        return $stored
            ->reject(fn (MailboxMessage $message): bool => in_array($message->message_id, $known, true))
            ->unique(fn (MailboxMessage $message): string => $message->message_id ?? sprintf('row:%d', $message->id))
            ->values();
    }
}
