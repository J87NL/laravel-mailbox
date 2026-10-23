<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Support\RawMessageCache;

class PruneBodiesCommand extends Command
{
    protected $signature = 'mailbox:prune-bodies {--days= : Drop cached bodies fetched longer ago than this many days}';

    protected $description = 'Forget cached message bodies, attachment lists and expired raw messages; they are fetched again when a message is opened';

    public function handle(RawMessageCache $raw): int
    {
        $this->components->info(sprintf('Pruned %d expired raw messages.', $raw->pruneExpired()));

        $days = $this->days();

        if (is_null($days)) {
            $this->components->info('Body pruning is switched off (mailbox.cache.body_days is null).');

            return self::SUCCESS;
        }

        $pruned = 0;

        MailboxMessage::query()
            ->select([
                'id',
                'mailbox_id',
            ])
            ->where('body_fetched_at', '<', now()->subDays($days))
            ->chunkById(500, function (Collection $messages) use (&$pruned, $raw): void {
                $raw->forget($messages);

                $pruned += MailboxMessage::query()
                    ->whereKey($messages->modelKeys())
                    ->update([
                        'attachments' => null,
                        'body_fetched_at' => null,
                        'body_html' => null,
                        'body_text' => null,
                    ]);
            });

        $this->components->info(sprintf('Pruned %d cached message bodies older than %d days.', $pruned, $days));

        return self::SUCCESS;
    }

    private function days(): ?int
    {
        $days = $this->option('days') ?? config('mailbox.cache.body_days');

        if (is_null($days) || $days === '') {
            return null;
        }

        return max(0, (int) $days);
    }
}
