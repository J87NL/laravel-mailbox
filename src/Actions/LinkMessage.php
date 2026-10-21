<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Exceptions\MailboxException;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Models\MailboxMessageLink;

class LinkMessage
{
    public function __construct(
        private readonly RecordAudit $audit,
    ) {}

    public function handle(MailboxMessage $message, Model $linkable, ?Authenticatable $user): MailboxMessageLink
    {
        if (blank($message->message_id)) {
            throw MailboxException::missingMessageId($message);
        }

        if (! $linkable->exists) {
            throw MailboxException::unsavedLinkable();
        }

        return $message->getConnection()->transaction(fn (): MailboxMessageLink => $this->link($message, $linkable, $user));
    }

    private function link(MailboxMessage $message, Model $linkable, ?Authenticatable $user): MailboxMessageLink
    {
        $link = MailboxMessageLink::query()
            ->firstOrCreate([
                'linkable_id' => $linkable->getKey(),
                'linkable_type' => $linkable->getMorphClass(),
                'mailbox_id' => $message->mailbox_id,
                'message_id' => $message->message_id,
            ], [
                'user_id' => $user?->getAuthIdentifier(),
            ]);

        if (! $link->wasRecentlyCreated) {
            return $link;
        }

        $this->audit->forMessages(
            collect([$message]),
            AuditAction::Linked,
            $user,
            meta: [
                'linkable' => sprintf('%s:%s', $linkable->getMorphClass(), $linkable->getKey()),
            ],
        );

        return $link;
    }
}
