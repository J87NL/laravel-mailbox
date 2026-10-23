<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Threading\ConversationKeys;

class ThreadCommand extends Command
{
    protected $signature = 'mailbox:threads {mailbox? : Mailbox id} {--fresh : Recompute the keys of messages that already have one}';

    protected $description = 'Give stored messages the conversation key the conversation view groups them by';

    public function handle(ConversationKeys $conversations): int
    {
        $assigned = 0;

        if ($this->option('fresh')) {
            $this->messages()->update([
                'thread_key' => null,
            ]);
        }

        do {
            /** @var Collection<int, MailboxMessage> $batch */
            $batch = $this->messages()
                ->whereNull('thread_key')
                ->orderByRaw('sent_at is null')
                ->orderBy('sent_at')
                ->orderBy('id')
                ->limit(200)
                ->get();

            $assigned += $conversations->assign($batch);
        } while ($batch->isNotEmpty());

        $this->components->info(sprintf('%d messages threaded.', $assigned));

        return self::SUCCESS;
    }

    /**
     * @return Builder<MailboxMessage>
     */
    private function messages(): Builder
    {
        return MailboxMessage::query()
            ->when($this->argument('mailbox'), fn (Builder $query, string $id): Builder => $query->where('mailbox_id', $id));
    }
}
