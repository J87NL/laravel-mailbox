<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Support\RawMessageCache;

class MoveMessages
{
    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function handle(
        Collection $messages,
        MailboxFolder $target,
        ?Authenticatable $user,
        AuditAction $action = AuditAction::Moved,
    ): int {
        $moved = $messages
            ->reject(fn (MailboxMessage $message): bool => $message->mailbox_folder_id === $target->id)
            ->groupBy('mailbox_folder_id')
            ->sum(fn (Collection $group): int => $this->moveGroup($group, $target, $user, $action));

        if ($moved > 0) {
            SyncFolderJob::dispatch($target->id);
        }

        return $moved;
    }

    /**
     * @param  Collection<int, MailboxMessage>  $group
     */
    private function moveGroup(Collection $group, MailboxFolder $target, ?Authenticatable $user, AuditAction $action): int
    {
        $query = $this->connector->folder($group->first()->folder)->messages();
        $query->uid($group->pluck('uid')->all());
        $query->move($target->path);

        $this->audit->forMessages($group, $action, $user, $target->path);
        $this->raw()->forget($group);

        MailboxMessage::query()
            ->whereKey($group->pluck('id')->all())
            ->delete();

        return $group->count();
    }

    private function raw(): RawMessageCache
    {
        return app(RawMessageCache::class);
    }
}
