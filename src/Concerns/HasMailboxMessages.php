<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Models\MailboxMessageLink;

trait HasMailboxMessages
{
    public function mailboxMessageLinks(): MorphMany
    {
        return $this->morphMany(MailboxMessageLink::class, 'linkable');
    }

    public function mailboxMessages(): Builder
    {
        return MailboxMessage::query()
            ->whereIn('message_id', $this->mailboxMessageLinks()->select('message_id'))
            ->latest('sent_at');
    }
}
