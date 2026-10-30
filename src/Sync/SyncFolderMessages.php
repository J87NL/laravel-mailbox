<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Support\RawMessageCache;

class SyncFolderMessages
{
    public const LOCK_KEY = 'mailbox-folder-sync:%d';

    public const DEFAULT_FLAG_CHUNK_SIZE = 100;

    public const STEP_STATUS = 'status';

    public const STEP_FETCH = 'fetch';

    public const STEP_CHUNK = 'chunk';

    public const STEP_REFRESH = 'refresh';

    public function __construct(
        private readonly AnnounceReceivedMessages $announcer,
        private readonly MailboxConnector $connector,
        private readonly StoreRemoteMessages $store,
    ) {}

    public function handle(MailboxFolder $folder, ?SyncBudget $budget = null): void
    {
        if (! $folder->selectable) {
            return;
        }

        if ($folder->skipsSync()) {
            $this->clearUnseenCount($folder);

            return;
        }

        $budget ??= SyncBudget::unlimited();

        Cache::lock(sprintf(self::LOCK_KEY, $folder->id), $this->lockSeconds($budget))
            ->get(function () use ($budget, $folder): void {
                $this->sync($folder, $budget);
            });
    }

    private function clearUnseenCount(MailboxFolder $folder): void
    {
        if ((int) $folder->unseen_count === 0) {
            return;
        }

        $folder->unseen_count = 0;
        $folder->save();
    }

    private function lockSeconds(SyncBudget $budget): int
    {
        if (! $budget->isLimited()) {
            return 600;
        }

        return (int) config('mailbox.sync.job_timeout_seconds', 55) + 60;
    }

    private function sync(MailboxFolder $folder, SyncBudget $budget): void
    {
        if (! $budget->allows($folder, self::STEP_STATUS)) {
            $budget->defer($folder, self::STEP_STATUS);

            return;
        }

        [$remote, $status] = $budget->measure($folder, self::STEP_STATUS, function () use ($folder): array {
            $remote = $this->connector->folder($folder);

            return [
                $remote,
                $remote->status(),
            ];
        });

        $this->resetWhenUidValidityChanged($folder, (int) ($status['UIDVALIDITY'] ?? 0));

        if (! $this->fetchNew($folder, $remote, ! is_null($folder->synced_at), $budget)) {
            $folder->save();

            return;
        }

        $this->refreshKnown($folder, $remote, $budget);

        $folder->unseen_count = isset($status['UNSEEN'])
            ? (int) $status['UNSEEN']
            : $folder->messages()->where('is_seen', false)->count();
        $folder->message_count = isset($status['MESSAGES'])
            ? (int) $status['MESSAGES']
            : $folder->messages()->count();
        $folder->synced_at = now();
        $folder->save();
    }

    private function resetWhenUidValidityChanged(MailboxFolder $folder, int $uidValidity): void
    {
        $changed = ! is_null($folder->uid_validity) && (int) $folder->uid_validity !== $uidValidity;

        $folder->uid_validity = $uidValidity;

        if (! $changed) {
            return;
        }

        app(RawMessageCache::class)->forgetMatching($folder->messages()->getQuery()->clone());
        $folder->messages()->delete();
        $folder->last_uid = 0;
        $folder->oldest_uid = null;
        $folder->older_exhausted = false;
        $folder->synced_at = null;
        Cache::forget(sprintf('mailbox-sync-refresh:%d', $folder->id));
    }

    private function fetchNew(MailboxFolder $folder, FolderInterface $remote, bool $established, SyncBudget $budget): bool
    {
        if (! $budget->allows($folder, self::STEP_FETCH)) {
            $budget->defer($folder, self::STEP_FETCH);

            return false;
        }

        $after = (int) $folder->last_uid;

        $query = $this->store->query($remote)->oldest();

        $this->constrainToNew($query, $after);

        $watched = $this->announcer->watches($folder);
        $complete = true;
        $started = now();
        $previousChunk = null;
        $chunkSize = max(1, (int) config('mailbox.sync.chunk_size'));

        $query->chunk(function (MessageCollection $messages) use ($after, $budget, $chunkSize, &$complete, $established, $folder, &$previousChunk, $started, $watched): ?bool {
            $budget->record($folder, is_null($previousChunk) ? self::STEP_FETCH : self::STEP_CHUNK, $previousChunk ?? $started);
            $previousChunk = now();

            $fresh = collect($messages->all())
                ->filter(fn (MessageInterface $message): bool => $message->uid() > $after)
                ->values();

            if ($fresh->isEmpty()) {
                return null;
            }

            $folder->last_uid = max((int) $folder->last_uid, (int) $fresh->max(fn (MessageInterface $message): int => $message->uid()));

            $stored = $this->store->handle($folder, $fresh);

            $folder->save();

            if ($watched) {
                $this->announcer->handle($folder, $stored, announce: $established);
            }

            if ($messages->count() < $chunkSize || $budget->allows($folder, self::STEP_CHUNK)) {
                return null;
            }

            $budget->defer($folder, self::STEP_FETCH);
            $complete = false;

            return false;
        }, $chunkSize);

        if (is_null($previousChunk)) {
            $budget->record($folder, self::STEP_FETCH, $started);
        }

        return $complete;
    }

    private function constrainToNew(MessageQueryInterface $query, int $after): void
    {
        if ($after > 0) {
            $query->uid($after + 1, INF);

            return;
        }

        $query->since(now()->subDays((int) config('mailbox.sync.initial_days')));
    }

    private function refreshKnown(MailboxFolder $folder, FolderInterface $remote, SyncBudget $budget): void
    {
        $resumeKey = sprintf('mailbox-sync-refresh:%d', $folder->id);
        $resumeAt = Cache::get($resumeKey);

        $window = $folder->messages()
            ->orderByDesc('uid')
            ->limit((int) config('mailbox.sync.flag_window'))
            ->pluck('uid')
            ->when(! is_null($resumeAt), fn (Collection $uids): Collection => $uids->filter(fn (int|string $uid): bool => (int) $uid <= (int) $resumeAt)->values());

        foreach ($window->chunk(max(1, (int) config('mailbox.sync.flag_chunk_size', self::DEFAULT_FLAG_CHUNK_SIZE))) as $known) {
            if (! $budget->allows($folder, self::STEP_REFRESH)) {
                Cache::put($resumeKey, (int) $known->first(), now()->addDay());
                $budget->defer($folder, self::STEP_REFRESH);

                return;
            }

            $budget->measure($folder, self::STEP_REFRESH, function () use ($folder, $known, $remote): void {
                $this->refreshFlags($folder, $remote, $known->values());
            });
        }

        Cache::forget($resumeKey);
    }

    /**
     * @param  Collection<int, int>  $known
     */
    private function refreshFlags(MailboxFolder $folder, FolderInterface $remote, Collection $known): void
    {
        $query = $remote->messages()
            ->leaveUnread()
            ->withFlags()
            ->withoutHeaders();

        $query->uid((int) $known->min(), (int) $known->max());

        $remoteMessages = $query->get();

        $remoteFlags = collect($remoteMessages->all())
            ->reject(fn (MessageInterface $message): bool => $this->store->isDeleted($message))
            ->mapWithKeys(fn (MessageInterface $message): array => [$message->uid() => $this->store->flagAttributes($message->flags())])
            ->only($known->all());

        $gone = $known->diff($remoteFlags->keys())->values()->all();

        if ($gone !== []) {
            app(RawMessageCache::class)->forgetMatching($folder->messages()->whereIn('uid', $gone)->getQuery()->clone());
        }

        $folder->messages()
            ->whereIn('uid', $gone)
            ->delete();

        $remoteFlags
            ->groupBy(fn (array $attributes): string => (string) json_encode($attributes), preserveKeys: true)
            ->each(function (Collection $group) use ($folder): void {
                $this->updateFlags($folder, $group->keys()->all(), $group->first());
            });
    }

    /**
     * @param  array<int, int>  $uids
     * @param  array<string, bool>  $attributes
     */
    private function updateFlags(MailboxFolder $folder, array $uids, array $attributes): void
    {
        $folder->messages()
            ->whereIn('uid', $uids)
            ->where(function (Builder $query) use ($attributes): void {
                foreach ($attributes as $column => $value) {
                    $query->orWhere($column, '!=', $value);
                }
            })
            ->update($attributes);
    }
}
