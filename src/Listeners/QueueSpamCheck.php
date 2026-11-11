<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Listeners;

use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Jobs\CheckSpamJob;

class QueueSpamCheck
{
    public function handle(MessageReceived $event): void
    {
        $message = $event->message;

        if (! is_null($message->spam_checked_at) || ! $message->folder->mailbox->spamFilterMode()->isActive()) {
            return;
        }

        CheckSpamJob::dispatch($message->id);
    }
}
