<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Spam\CheckSpam;

class CheckSpamJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $messageId,
    ) {
        $this->onQueue(config('mailbox.spam.queue'));
    }

    public function handle(CheckSpam $check, MailboxConnector $connector): void
    {
        $message = MailboxMessage::query()
            ->with('folder.mailbox')
            ->find($this->messageId);

        if (is_null($message)) {
            return;
        }

        try {
            $check->handle($message);
        } finally {
            $connector->disconnect($message->folder->mailbox);
        }
    }
}
