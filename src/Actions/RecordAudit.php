<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxMessage;

class RecordAudit
{
    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function forMessages(
        Collection $messages,
        AuditAction $action,
        ?Authenticatable $user,
        ?string $targetPath = null,
    ): void {
        if ($messages->isEmpty()) {
            return;
        }

        $createdAt = now();
        $userId = $user?->getAuthIdentifier();

        MailboxAuditEntry::query()->insert(
            $messages
                ->map(fn (MailboxMessage $message): array => [
                    'action' => $action->value,
                    'created_at' => $createdAt,
                    'folder_path' => $message->folder->path,
                    'mailbox_id' => $message->mailbox_id,
                    'message_id' => $message->message_id,
                    'meta' => null,
                    'subject' => $message->subject,
                    'target_path' => $targetPath,
                    'user_id' => $userId,
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function forMailbox(
        Mailbox $mailbox,
        AuditAction $action,
        ?Authenticatable $user,
        ?string $messageId = null,
        ?string $subject = null,
        ?string $targetPath = null,
        array $meta = [],
    ): MailboxAuditEntry {
        return MailboxAuditEntry::query()->create([
            'action' => $action,
            'mailbox_id' => $mailbox->id,
            'message_id' => $messageId,
            'meta' => $meta ?: null,
            'subject' => $subject,
            'target_path' => $targetPath,
            'user_id' => $user?->getAuthIdentifier(),
        ]);
    }
}
