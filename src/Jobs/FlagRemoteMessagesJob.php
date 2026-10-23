<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Jobs;

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use Illuminate\Foundation\Bus\Dispatchable;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\MailboxFolder;
use Throwable;

class FlagRemoteMessagesJob
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $uids
     */
    public function __construct(
        public readonly int $folderId,
        public readonly array $uids,
        public readonly ImapFlag $flag,
        public readonly string $operation,
    ) {}

    public function handle(MailboxConnector $connector): void
    {
        if ($this->uids === []) {
            return;
        }

        try {
            $folder = MailboxFolder::query()
                ->with('mailbox')
                ->find($this->folderId);

            if (is_null($folder)) {
                return;
            }

            $query = $connector->folder($folder)->messages();
            $query->uid($this->uids);
            $query->flag($this->flag, $this->operation);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            if (isset($folder)) {
                rescue(fn () => $connector->disconnect($folder->mailbox));
            }
        }
    }
}
