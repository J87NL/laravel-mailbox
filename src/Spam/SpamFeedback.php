<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use J87NL\Mailbox\Enums\SpamAllowlistSource;
use J87NL\Mailbox\Enums\SpamStatus;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxMessage;

class SpamFeedback
{
    public function __construct(
        private readonly SpamAllowlist $allowlist,
    ) {}

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function markedJunk(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): void
    {
        if (! $this->learns($mailbox, $messages, $user)) {
            return;
        }

        MailboxMessage::query()
            ->whereKey($messages->pluck('id')->all())
            ->where('spam_status', SpamStatus::Suggested)
            ->update(['spam_status' => null]);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function markedNotJunk(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): void
    {
        if (! $this->learns($mailbox, $messages, $user)) {
            return;
        }

        $messages
            ->pluck('from_email')
            ->filter()
            ->map(fn (string $email): string => Str::lower($email))
            ->unique()
            ->each(function (string $email) use ($mailbox): void {
                $this->allowlist->add($mailbox, $email, SpamAllowlistSource::NotJunk);
            });

        MailboxMessage::query()
            ->whereKey($messages->pluck('id')->all())
            ->update([
                'spam_checked_at' => now(),
                'spam_status' => SpamStatus::Released,
            ]);
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    private function learns(Mailbox $mailbox, Collection $messages, ?Authenticatable $user): bool
    {
        return ! is_null($user) && $messages->isNotEmpty() && $mailbox->spamFilterMode()->isActive();
    }
}
