<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;

class MarkJunk
{
    public const JUNK_KEYWORD = '$Junk';

    public const NOT_JUNK_KEYWORD = '$NotJunk';

    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly EnsureFolder $ensureFolder,
        private readonly MoveMessages $moveMessages,
    ) {}

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function junk(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): int
    {
        $junk = $this->ensureFolder->handle($mailbox, FolderRole::Junk, (string) config('mailbox.folders.junk_fallback', 'Junk'));

        return $this->moveWithKeyword($messages, $junk, self::JUNK_KEYWORD, self::NOT_JUNK_KEYWORD, $user, AuditAction::MarkedJunk);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function notJunk(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): int
    {
        $inbox = $this->ensureFolder->handle($mailbox, FolderRole::Inbox, 'INBOX');

        return $this->moveWithKeyword($messages, $inbox, self::NOT_JUNK_KEYWORD, self::JUNK_KEYWORD, $user, AuditAction::MarkedNotJunk);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    private function moveWithKeyword(
        Collection $messages,
        MailboxFolder $target,
        string $add,
        string $remove,
        ?Authenticatable $user,
        AuditAction $action,
    ): int {
        $messages
            ->reject(fn (MailboxMessage $message): bool => $message->mailbox_folder_id === $target->id)
            ->groupBy('mailbox_folder_id')
            ->each(function (Collection $group) use ($add, $remove): void {
                $this->keywords($group, $add, $remove);
            });

        return $this->moveMessages->handle($messages, $target, $user, $action);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $group
     */
    private function keywords(Collection $group, string $add, string $remove): void
    {
        $folder = $group->first()->folder;

        $uids = $group
            ->pluck('uid')
            ->map(fn (mixed $uid): int => (int) $uid)
            ->values()
            ->all();

        try {
            $this->connector->storeKeywords($folder, $uids, $add, $remove);
        } catch (ImapCommandException $exception) {
            Log::info('The mail server did not store the junk keywords, the messages are moved without them.', [
                'error' => $exception->getMessage(),
                'mailbox_folder_id' => $folder->id,
                'mailbox_id' => $folder->mailbox_id,
            ]);
        }
    }
}
