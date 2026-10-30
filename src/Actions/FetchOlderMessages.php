<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Sync\AnnounceReceivedMessages;
use J87NL\Mailbox\Sync\StoreRemoteMessages;
use J87NL\Mailbox\Sync\SyncFolderMessages;

class FetchOlderMessages
{
    public const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly AnnounceReceivedMessages $announcer,
        private readonly MailboxConnector $connector,
        private readonly StoreRemoteMessages $store,
    ) {}

    public function handle(MailboxFolder $folder): ?int
    {
        $stored = Cache::lock(sprintf(SyncFolderMessages::LOCK_KEY, $folder->id), $this->lockSeconds())
            ->get(fn (): int => $this->fetchWhenOffered($folder));

        return is_int($stored) ? $stored : null;
    }

    private function lockSeconds(): int
    {
        return (int) config('mailbox.sync.job_timeout_seconds', SyncFolderJob::DEFAULT_TIMEOUT) + 60;
    }

    private function fetchWhenOffered(MailboxFolder $folder): int
    {
        $folder->refresh();

        if (! $folder->selectable || $folder->skipsSync() || $folder->older_exhausted) {
            return 0;
        }

        $remote = $this->connector->folder($folder);

        if ($this->uidValidityChanged($folder, $remote)) {
            SyncFolderJob::dispatch($folder->id);

            return 0;
        }

        return $this->fetch($folder, $remote);
    }

    private function uidValidityChanged(MailboxFolder $folder, FolderInterface $remote): bool
    {
        $status = $remote->status();

        if (is_null($folder->uid_validity) || ! isset($status['UIDVALIDITY'])) {
            return false;
        }

        return (int) $folder->uid_validity !== (int) $status['UIDVALIDITY'];
    }

    private function fetch(MailboxFolder $folder, FolderInterface $remote): int
    {
        $upperUid = $this->upperUid($folder);

        if ($upperUid < 1) {
            $this->markExhausted($folder);

            return 0;
        }

        $batchSize = max(1, (int) config('mailbox.sync.older_batch_size', self::DEFAULT_BATCH_SIZE));

        $query = $this->store->query($remote)
            ->newest()
            ->limit($batchSize);

        $query->uid(1, $upperUid);

        $messages = collect($query->get()->all());

        $stored = $this->store->handle($folder, $messages);

        if ($this->announcer->watches($folder)) {
            $this->announcer->handle($folder, $stored, announce: false);
        }

        $this->remember($folder, $messages);

        return count($stored);
    }

    /**
     * @param  Collection<int, MessageInterface>  $messages
     */
    private function remember(MailboxFolder $folder, Collection $messages): void
    {
        if ($messages->isNotEmpty()) {
            $folder->oldest_uid = (int) $messages->min(fn (MessageInterface $message): int => $message->uid());
        }

        $folder->older_exhausted = $messages->isEmpty() || (int) $folder->oldest_uid <= 1;
        $folder->save();
    }

    private function markExhausted(MailboxFolder $folder): void
    {
        $folder->older_exhausted = true;
        $folder->save();
    }

    private function upperUid(MailboxFolder $folder): int|float
    {
        $lowestUid = collect([
            $folder->messages()->min('uid'),
            $folder->oldest_uid,
        ])
            ->reject(fn (mixed $uid): bool => is_null($uid))
            ->map(fn (int|string $uid): int => (int) $uid)
            ->min();

        if (! is_null($lowestUid)) {
            return $lowestUid - 1;
        }

        if ((int) $folder->last_uid > 0) {
            return (int) $folder->last_uid;
        }

        return INF;
    }
}
