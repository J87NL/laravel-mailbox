<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use Illuminate\Support\Facades\Log;
use J87NL\Mailbox\Actions\MarkJunk;
use J87NL\Mailbox\Contracts\ClassifiesSpam;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\SpamFilterMode;
use J87NL\Mailbox\Enums\SpamStatus;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxMessage;
use Throwable;

class CheckSpam
{
    public const DEFAULT_MOVE_THRESHOLD = 0.95;

    public const DEFAULT_SUGGEST_THRESHOLD = 0.80;

    public function __construct(
        private readonly ClassifiesSpam $classifier,
        private readonly MarkJunk $markJunk,
        private readonly SpamAllowlist $allowlist,
    ) {}

    public function handle(MailboxMessage $message): void
    {
        $mailbox = $message->folder->mailbox;
        $mode = $mailbox->spamFilterMode();

        if (! $mode->isActive() || ! is_null($message->spam_checked_at)) {
            return;
        }

        if ($this->allowlist->matches($mailbox, $message->from_email)) {
            $this->store($message, null, null);

            return;
        }

        $verdict = $this->classify($message);

        if (is_null($verdict) || ! $verdict->hasScore()) {
            $this->store($message, null, null);

            return;
        }

        $status = $this->status($mode, (float) $verdict->score);

        if ($status === SpamStatus::Moved && $this->moveToJunk($mailbox, $message, $verdict)) {
            return;
        }

        $this->store($message, $verdict, $status === SpamStatus::Moved ? SpamStatus::Suggested : $status);
    }

    private function classify(MailboxMessage $message): ?SpamVerdict
    {
        try {
            $verdict = $this->classifier->classify($message);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! $verdict->hasScore()) {
            Log::debug('The spam classifier gave no verdict, the message stays where it is.', [
                'mailbox_message_id' => $message->id,
                'reason' => $verdict->reason,
            ]);
        }

        return $verdict;
    }

    private function status(SpamFilterMode $mode, float $score): ?SpamStatus
    {
        if ($mode === SpamFilterMode::Measure) {
            return null;
        }

        if ($mode === SpamFilterMode::Auto && $score >= $this->threshold('move_threshold', self::DEFAULT_MOVE_THRESHOLD)) {
            return SpamStatus::Moved;
        }

        if ($score >= $this->threshold('suggest_threshold', self::DEFAULT_SUGGEST_THRESHOLD)) {
            return SpamStatus::Suggested;
        }

        return null;
    }

    private function threshold(string $key, float $default): float
    {
        return (float) config(sprintf('mailbox.spam.%s', $key), $default);
    }

    private function moveToJunk(Mailbox $mailbox, MailboxMessage $message, SpamVerdict $verdict): bool
    {
        try {
            $moved = $this->markJunk->junk($mailbox, collect([$message]), null, AuditAction::SpamMoved, [
                'reason' => $verdict->reason,
                'score' => $verdict->score,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return $moved > 0;
    }

    private function store(MailboxMessage $message, ?SpamVerdict $verdict, ?SpamStatus $status): void
    {
        $message->forceFill([
            'spam_checked_at' => now(),
            'spam_reason' => $verdict?->reason,
            'spam_score' => $verdict?->score,
            'spam_status' => $status,
        ])->save();
    }
}
