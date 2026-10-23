<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Support\RawMessageCache;

class ForgetMessage
{
    public function handle(MailboxMessage $message): void
    {
        $message->delete();
        $this->raw()->forget(collect([$message]));

        SyncFolderJob::dispatch($message->mailbox_folder_id);
    }

    private function raw(): RawMessageCache
    {
        return app(RawMessageCache::class);
    }
}
