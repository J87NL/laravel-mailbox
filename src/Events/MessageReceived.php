<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use J87NL\Mailbox\Models\MailboxMessage;

class MessageReceived
{
    use Dispatchable;

    public function __construct(
        public readonly MailboxMessage $message,
    ) {}
}
