<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Access\MailboxAccessResolver;
use J87NL\Mailbox\Enums\AccessLevel;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAccess;

class GrantAccess
{
    public function __construct(
        private readonly MailboxAccessResolver $resolver,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Mailbox $mailbox, string $type, int|string $id, AccessLevel $level, ?Authenticatable $user): MailboxAccess
    {
        $existing = MailboxAccess::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('accessor_type', $type)
            ->where('accessor_id', $id)
            ->first();

        if ($existing && $level !== AccessLevel::Manage) {
            $this->assertAnotherManagerRemains($existing);
        }

        $access = MailboxAccess::query()
            ->updateOrCreate(
                [
                    'accessor_id' => $id,
                    'accessor_type' => $type,
                    'mailbox_id' => $mailbox->id,
                ],
                [
                    'level' => $level,
                ],
            );

        $this->audit->forMailbox($mailbox, AuditAction::AccessGranted, $user, meta: [
            'accessor' => sprintf('%s:%s', $type, $id),
            'level' => $level->value,
        ]);

        $this->resolver->flush();

        return $access;
    }

    public function revoke(MailboxAccess $access, ?Authenticatable $user): void
    {
        $this->assertAnotherManagerRemains($access);

        $this->audit->forMailbox($access->mailbox, AuditAction::AccessRevoked, $user, meta: [
            'accessor' => sprintf('%s:%s', $access->accessor_type, $access->accessor_id),
            'level' => $access->level->value,
        ]);

        $access->delete();

        $this->resolver->flush();
    }

    private function assertAnotherManagerRemains(MailboxAccess $access): void
    {
        if ($access->level !== AccessLevel::Manage) {
            return;
        }

        $anotherManager = MailboxAccess::query()
            ->where('mailbox_id', $access->mailbox_id)
            ->where('level', AccessLevel::Manage)
            ->whereKeyNot($access->id)
            ->exists();

        if ($anotherManager) {
            return;
        }

        throw ValidationException::withMessages([
            'accessor' => __('mailbox::mailbox.last_manager'),
        ]);
    }
}
