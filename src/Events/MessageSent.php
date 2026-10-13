<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use J87NL\Mailbox\Models\Mailbox;

class MessageSent
{
    use Dispatchable;

    public function __construct(
        public readonly Mailbox $mailbox,
        public readonly string $messageId,
        public readonly string $subject,
    ) {}
}
