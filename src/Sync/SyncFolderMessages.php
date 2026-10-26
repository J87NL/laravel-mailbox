<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Support\RawMessageCache;
use J87NL\Mailbox\Threading\ConversationKeys;

class SyncFolderMessages
{
    private const STRING_LENGTH = 255;

    private const TEXT_BYTES = 65535;

    private const UPDATED_COLUMNS = [
        'cc',
        'from_email',
        'from_name',
        'has_attachments',
        'in_reply_to',
        'is_answered',
        'is_flagged',
        'is_seen',
        'message_id',
        'references',
        'reply_to',
        'sent_at',
        'size',
        'subject',
        'to',
    ];

    public function __construct(
        private readonly AnnounceReceivedMessages $announcer,
        private readonly MailboxConnector $connector,
        private readonly ConversationKeys $conversations,
    ) {}

    public function handle(MailboxFolder $folder, ?SyncBudget $budget = null): void
    {
        if (! $folder->selectable) {
            return;
        }

        $budget ??= SyncBudget::unlimited();

        Cache::lock(sprintf('mailbox-folder-sync:%d', $folder->id), $this->lockSeconds($budget))
            ->get(function () use ($budget, $folder): void {
                $this->sync($folder, $budget);
            });
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
        $remote = $this->connector->folder($folder);
        $status = $remote->status();

        $this->resetWhenUidValidityChanged($folder, (int) ($status['UIDVALIDITY'] ?? 0));

        if (! $this->fetchNew($folder, $remote, ! is_null($folder->synced_at), $budget)) {
            $folder->save();

            return;
        }

        $this->refreshKnown($folder, $remote);

        $folder->unseen_count = isset($status['UNSEEN'])
            ? (int) $status['UNSEEN']
            : $folder->messages()->where('is_seen', false)->count();
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
        $folder->synced_at = null;
    }

    private function fetchNew(MailboxFolder $folder, FolderInterface $remote, bool $established, SyncBudget $budget): bool
    {
        $after = (int) $folder->last_uid;

        $query = $remote->messages()
            ->oldest()
            ->leaveUnread()
            ->withHeaders()
            ->withFlags()
            ->withSize()
            ->withBodyStructure();

        $this->constrainToNew($query, $after);

        $watched = $this->announcer->watches($folder);
        $complete = true;

        $query->chunk(function (MessageCollection $messages) use ($after, $budget, &$complete, $established, $folder, $watched): ?bool {
            $fresh = collect($messages->all())
                ->filter(fn (MessageInterface $message): bool => $message->uid() > $after)
                ->values();

            if ($fresh->isEmpty()) {
                return null;
            }

            $folder->last_uid = max((int) $folder->last_uid, (int) $fresh->max(fn (MessageInterface $message): int => $message->uid()));

            $stored = $this->store($folder, $fresh->reject(fn (MessageInterface $message): bool => $this->isDeleted($message)));

            $folder->save();

            if ($watched) {
                $this->announcer->handle($folder, $stored, announce: $established);
            }

            if (! $budget->exhausted()) {
                return null;
            }

            $complete = false;

            return false;
        }, (int) config('mailbox.sync.chunk_size'));

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

    /**
     * @param  Collection<int, MessageInterface>  $messages
     * @return array<int, int>
     */
    private function store(MailboxFolder $folder, Collection $messages): array
    {
        if ($messages->isEmpty()) {
            return [];
        }

        $uids = $messages->map(fn (MessageInterface $message): int => $message->uid())->values()->all();

        $existing = $folder->messages()
            ->whereIn('uid', $uids)
            ->pluck('uid')
            ->all();

        MailboxMessage::query()
            ->upsert(
                $messages->map(fn (MessageInterface $message): array => $this->attributes($folder, $message))->values()->all(),
                [
                    'mailbox_folder_id',
                    'uid',
                ],
                self::UPDATED_COLUMNS,
            );

        $this->conversations->assign($folder->messages()
            ->whereIn('uid', $uids)
            ->whereNull('thread_key')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get());

        return array_values(array_diff($uids, $existing));
    }

    private function refreshKnown(MailboxFolder $folder, FolderInterface $remote): void
    {
        $known = $folder->messages()
            ->orderByDesc('uid')
            ->limit((int) config('mailbox.sync.flag_window'))
            ->pluck('uid');

        if ($known->isEmpty()) {
            return;
        }

        $query = $remote->messages()
            ->leaveUnread()
            ->withFlags()
            ->withoutHeaders();

        $query->uid((int) $known->min(), (int) $known->max());

        $remoteMessages = $query->get();

        $remoteFlags = collect($remoteMessages->all())
            ->reject(fn (MessageInterface $message): bool => $this->isDeleted($message))
            ->mapWithKeys(fn (MessageInterface $message): array => [$message->uid() => $this->flagAttributes($message->flags())])
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

    /**
     * @return array<string, mixed>
     */
    private function attributes(MailboxFolder $folder, MessageInterface $message): array
    {
        $from = $message->from();

        return [
            'cc' => json_encode($this->addresses($message->cc())),
            'from_email' => $this->limit($from?->email()),
            'from_name' => $this->limit($from?->name()),
            'has_attachments' => count($message->bodyStructure()?->attachments() ?? []) > 0,
            'in_reply_to' => $this->limit($message->inReplyTo()[0] ?? null),
            'mailbox_folder_id' => $folder->id,
            'mailbox_id' => $folder->mailbox_id,
            'message_id' => $this->limit($message->messageId()),
            'references' => $this->limitBytes($message->header('References')?->getRawValue()),
            'reply_to' => json_encode($this->addresses($message->addresses('Reply-To'))),
            'sent_at' => $message->date()?->setTimezone((string) config('app.timezone')),
            'size' => $message->size(),
            'subject' => $this->limit($message->subject()),
            'to' => json_encode($this->addresses($message->to())),
            'uid' => $message->uid(),
            ...$this->flagAttributes($message->flags()),
        ];
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array{email: string, name: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): array => [
            'email' => $address->email(),
            'name' => $address->name(),
        ], $addresses));
    }

    /**
     * @param  array<int, string>  $flags
     * @return array{is_answered: bool, is_flagged: bool, is_seen: bool}
     */
    private function flagAttributes(array $flags): array
    {
        return [
            'is_answered' => in_array(ImapFlag::Answered->value, $flags, true),
            'is_flagged' => in_array(ImapFlag::Flagged->value, $flags, true),
            'is_seen' => in_array(ImapFlag::Seen->value, $flags, true),
        ];
    }

    private function isDeleted(MessageInterface $message): bool
    {
        return in_array(ImapFlag::Deleted->value, $message->flags(), true);
    }

    private function limit(?string $value): ?string
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        return Str::limit($value, self::STRING_LENGTH, '');
    }

    private function limitBytes(?string $value): ?string
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        return mb_strcut($value, 0, self::TEXT_BYTES);
    }
}
