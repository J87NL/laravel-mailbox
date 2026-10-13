<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use J87NL\Mailbox\Jobs\SyncMailboxJob;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Sync\SyncMailbox;

class SyncCommand extends Command
{
    protected $signature = 'mailbox:sync {mailbox? : Mailbox id} {--now : Run inline instead of queueing}';

    protected $description = 'Synchronise folders and messages of shared mailboxes';

    public function handle(SyncMailbox $sync): int
    {
        $mailboxes = Mailbox::query()
            ->where('active', true)
            ->when($this->argument('mailbox'), fn (Builder $query, string $id): Builder => $query->whereKey($id))
            ->get();

        foreach ($mailboxes as $mailbox) {
            if (! $this->option('now')) {
                SyncMailboxJob::dispatch($mailbox->id);

                continue;
            }

            $this->components->task($mailbox->name, fn () => $sync->handle($mailbox));
        }

        return self::SUCCESS;
    }
}
