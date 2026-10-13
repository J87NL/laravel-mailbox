<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Models\MailboxMessageLink;

trait HasMailboxMessages
{
    /**
     * @return MorphMany<MailboxMessageLink, $this>
     */
    public function mailboxMessageLinks(): MorphMany
    {
        return $this->morphMany(MailboxMessageLink::class, 'linkable');
    }

    /**
     * @return Builder<MailboxMessage>
     */
    public function mailboxMessages(): Builder
    {
        $firstCopies = MailboxMessage::query()
            ->selectRaw('min(mailbox_messages.id)')
            ->whereExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('mailbox_message_links')
                    ->whereColumn('mailbox_message_links.mailbox_id', 'mailbox_messages.mailbox_id')
                    ->whereColumn('mailbox_message_links.message_id', 'mailbox_messages.message_id')
                    ->where('mailbox_message_links.linkable_type', $this->getMorphClass())
                    ->where('mailbox_message_links.linkable_id', $this->getKey());
            })
            ->groupBy('mailbox_messages.mailbox_id', 'mailbox_messages.message_id');

        return MailboxMessage::query()
            ->whereIn('id', $firstCopies)
            ->latest('sent_at');
    }
}
