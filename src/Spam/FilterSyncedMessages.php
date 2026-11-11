<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use J87NL\Mailbox\Actions\MarkJunk;
use J87NL\Mailbox\Actions\RecordAudit;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Enums\SpamStatus;
use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Sync\AnnounceReceivedMessages;
use Throwable;

class FilterSyncedMessages
{
    private const JUNK_ACTIONS = [
        AuditAction::MarkedJunk,
        AuditAction::MarkedNotJunk,
        AuditAction::SpamMoved,
    ];

    private const BATCH_SIZE = 500;

    private const USER_MOVES = [
        AuditAction::Archived,
        AuditAction::Moved,
    ];

    private const SKIPPED_ROLES = [
        FolderRole::Drafts,
        FolderRole::Sent,
        FolderRole::Trash,
    ];

    public function __construct(
        private readonly AnnounceReceivedMessages $announcer,
        private readonly MailboxConnector $connector,
        private readonly MarkJunk $markJunk,
        private readonly RecordAudit $audit,
        private readonly SenderAuthentication $authentication,
        private readonly SpamAllowlist $allowlist,
    ) {}

    /**
     * @param  array<int, int>  $uids
     */
    public function handle(MailboxFolder $folder, array $uids): void
    {
        if ($uids === [] || in_array($folder->role, self::SKIPPED_ROLES, true) || ! $folder->mailbox->spamFilterMode()->isActive()) {
            return;
        }

        foreach (array_chunk($uids, self::BATCH_SIZE) as $batch) {
            $this->handleBatch($folder, $batch);
        }
    }

    /**
     * @param  array<int, int>  $uids
     */
    private function handleBatch(MailboxFolder $folder, array $uids): void
    {
        $messages = $folder->messages()
            ->whereIn('uid', $uids)
            ->whereNotNull('message_id')
            ->get()
            ->each(fn (MailboxMessage $message): MailboxMessage => $message->setRelation('folder', $folder));

        if ($messages->isEmpty()) {
            return;
        }

        $history = $this->history($folder, $messages);

        $this->restoreVerdicts($messages, $history);
        $this->announceReturned($folder, $messages, $history);
        $this->releaseAllowlisted($folder, $messages, $history);
    }

    /**
     * @param  EloquentCollection<int, MailboxMessage>  $messages
     * @return Collection<string, Collection<int, MailboxAuditEntry>>
     */
    private function history(MailboxFolder $folder, EloquentCollection $messages): Collection
    {
        return MailboxAuditEntry::query()
            ->where('mailbox_id', $folder->mailbox_id)
            ->whereIn('message_id', $messages->pluck('message_id')->unique()->values()->all())
            ->orderBy('id')
            ->get([
                'action',
                'id',
                'message_id',
                'meta',
                'user_id',
            ])
            ->toBase()
            ->groupBy('message_id');
    }

    /**
     * @param  EloquentCollection<int, MailboxMessage>  $messages
     * @param  Collection<string, Collection<int, MailboxAuditEntry>>  $history
     */
    private function restoreVerdicts(EloquentCollection $messages, Collection $history): void
    {
        $messages->each(function (MailboxMessage $message) use ($history): void {
            $entries = $history->get($message->message_id, collect());

            if (is_null($this->latestJunkEntry($entries))) {
                return;
            }

            $latest = $entries
                ->filter(fn (MailboxAuditEntry $entry): bool => in_array($entry->action, [...self::JUNK_ACTIONS, ...self::USER_MOVES], true))
                ->last();

            $verdict = $entries
                ->filter(fn (MailboxAuditEntry $entry): bool => $entry->action === AuditAction::SpamMoved)
                ->last();

            $message->forceFill([
                'spam_checked_at' => now(),
                'spam_reason' => $verdict?->meta['reason'] ?? null,
                'spam_score' => $verdict?->meta['score'] ?? null,
                'spam_status' => $this->restoredStatus($latest, ! is_null($verdict)),
            ])->save();
        });
    }

    private function restoredStatus(MailboxAuditEntry $latest, bool $movedByFilter): ?SpamStatus
    {
        if ($latest->action === AuditAction::SpamMoved) {
            return SpamStatus::Moved;
        }

        if (is_null($latest->user_id)) {
            return null;
        }

        if ($latest->action === AuditAction::MarkedNotJunk || ($movedByFilter && in_array($latest->action, self::USER_MOVES, true))) {
            return SpamStatus::Released;
        }

        return null;
    }

    /**
     * @param  EloquentCollection<int, MailboxMessage>  $messages
     * @param  Collection<string, Collection<int, MailboxAuditEntry>>  $history
     */
    private function announceReturned(MailboxFolder $folder, EloquentCollection $messages, Collection $history): void
    {
        if (! $this->announcer->watches($folder)) {
            return;
        }

        $returned = $messages
            ->filter(function (MailboxMessage $message) use ($history): bool {
                $entries = $history->get($message->message_id, collect());

                return $this->latestJunkEntry($entries)?->action === AuditAction::MarkedNotJunk
                    && $entries->doesntContain(fn (MailboxAuditEntry $entry): bool => $entry->action === AuditAction::Received);
            })
            ->values();

        $this->audit->forMessages($returned, AuditAction::Received, null);

        $returned->each(function (MailboxMessage $message): void {
            MessageReceived::dispatch($message);
        });
    }

    /**
     * @param  EloquentCollection<int, MailboxMessage>  $messages
     * @param  Collection<string, Collection<int, MailboxAuditEntry>>  $history
     */
    private function releaseAllowlisted(MailboxFolder $folder, EloquentCollection $messages, Collection $history): void
    {
        if ($folder->role !== FolderRole::Junk) {
            return;
        }

        $untouched = $messages
            ->filter(fn (MailboxMessage $message): bool => $history
                ->get($message->message_id, collect())
                ->every(fn (MailboxAuditEntry $entry): bool => $entry->action === AuditAction::Received))
            ->values();

        $allowed = $this->allowlist->allowedSenders($folder->mailbox, $untouched->pluck('from_email'));

        $released = $untouched
            ->filter(fn (MailboxMessage $message): bool => $allowed->contains(Str::lower((string) $message->from_email)))
            ->values();

        if ($released->isEmpty()) {
            return;
        }

        try {
            $authenticated = $this->authenticated($folder, $released);

            if ($authenticated->isEmpty()) {
                return;
            }

            $this->markJunk->notJunk($folder->mailbox, $authenticated, null);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  Collection<int, MailboxMessage>  $candidates
     * @return Collection<int, MailboxMessage>
     */
    private function authenticated(MailboxFolder $folder, Collection $candidates): Collection
    {
        $query = $this->connector->folder($folder)
            ->messages()
            ->leaveUnread()
            ->withHeaders()
            ->withFlags();

        $query->uid($candidates->map(fn (MailboxMessage $message): int => (int) $message->uid)->values()->all());

        $remote = collect($query->get()->all())
            ->keyBy(fn (MessageInterface $message): int => $message->uid());

        return $candidates
            ->filter(function (MailboxMessage $message) use ($remote): bool {
                $fetched = $remote->get((int) $message->uid);

                if (is_null($fetched) || in_array(MarkJunk::JUNK_KEYWORD, $fetched->flags(), true)) {
                    return false;
                }

                return $this->authentication->passes($message->from_email, $fetched->header('Authentication-Results')?->getRawValue());
            })
            ->values();
    }

    /**
     * @param  Collection<int, MailboxAuditEntry>  $entries
     */
    private function latestJunkEntry(Collection $entries): ?MailboxAuditEntry
    {
        return $entries
            ->filter(fn (MailboxAuditEntry $entry): bool => in_array($entry->action, self::JUNK_ACTIONS, true))
            ->last();
    }
}
