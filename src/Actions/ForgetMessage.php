<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxMessage;

class ForgetMessage
{
    public function handle(MailboxMessage $message): void
    {
        $message->delete();

        SyncFolderJob::dispatch($message->mailbox_folder_id);
    }
}
