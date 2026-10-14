<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;

class MarkMessages
{
    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function read(Collection $messages, ?Authenticatable $user): void
    {
        $this->apply($messages, ImapFlag::Seen, '+', ['is_seen' => true], AuditAction::MarkedRead, $user);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function unread(Collection $messages, ?Authenticatable $user): void
    {
        $this->apply($messages, ImapFlag::Seen, '-', ['is_seen' => false], AuditAction::MarkedUnread, $user);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function flag(Collection $messages, ?Authenticatable $user): void
    {
        $this->apply($messages, ImapFlag::Flagged, '+', ['is_flagged' => true], AuditAction::Flagged, $user);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function unflag(Collection $messages, ?Authenticatable $user): void
    {
        $this->apply($messages, ImapFlag::Flagged, '-', ['is_flagged' => false], AuditAction::Unflagged, $user);
    }

    public function answered(MailboxMessage $message): void
    {
        $this->apply(collect([$message]), ImapFlag::Answered, '+', ['is_answered' => true], null, null);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     * @param  array<string, bool>  $attributes
     */
    private function apply(
        Collection $messages,
        ImapFlag $flag,
        string $operation,
        array $attributes,
        ?AuditAction $action,
        ?Authenticatable $user,
    ): void {
        $messages
            ->groupBy('mailbox_folder_id')
            ->each(function (Collection $group) use ($action, $attributes, $flag, $operation, $user): void {
                $query = $this->connector->folder($group->first()->folder)->messages();
                $query->uid($group->pluck('uid')->all());
                $query->flag($flag, $operation);

                $this->adjustUnseenCount($group, $attributes);

                MailboxMessage::query()
                    ->whereKey($group->pluck('id')->all())
                    ->update($attributes);

                if (is_null($action)) {
                    return;
                }

                $this->audit->forMessages($group, $action, $user);
            });
    }

    /**
     * @param  Collection<int, MailboxMessage>  $group
     * @param  array<string, bool>  $attributes
     */
    private function adjustUnseenCount(Collection $group, array $attributes): void
    {
        if (! array_key_exists('is_seen', $attributes)) {
            return;
        }

        $changed = MailboxMessage::query()
            ->whereKey($group->pluck('id')->all())
            ->where('is_seen', ! $attributes['is_seen'])
            ->count();

        if ($changed === 0) {
            return;
        }

        $folder = MailboxFolder::query()
            ->whereKey($group->first()->mailbox_folder_id);

        if (! $attributes['is_seen']) {
            $folder->increment('unseen_count', $changed);

            return;
        }

        $folder->update([
            'unseen_count' => DB::raw(sprintf('CASE WHEN unseen_count > %1$d THEN unseen_count - %1$d ELSE 0 END', $changed)),
        ]);
    }
}
