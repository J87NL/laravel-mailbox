<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    private const REASON_LENGTH = 255;

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

        $status = $this->status($mode, (float) $verdict->score, $this->isHandled($message));

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

    private function isHandled(MailboxMessage $message): bool
    {
        return $message->is_seen || $message->is_flagged || $message->is_answered;
    }

    private function status(SpamFilterMode $mode, float $score, bool $handled): ?SpamStatus
    {
        if ($mode === SpamFilterMode::Measure) {
            return null;
        }

        $thresholds = SpamThresholds::fromConfig();

        if ($mode === SpamFilterMode::Auto && ! $handled && $score >= $thresholds->move) {
            return SpamStatus::Moved;
        }

        if ($score >= $thresholds->suggest) {
            return SpamStatus::Suggested;
        }

        return null;
    }

    private function moveToJunk(Mailbox $mailbox, MailboxMessage $message, SpamVerdict $verdict): bool
    {
        try {
            $moved = $this->markJunk->junk($mailbox, collect([$message]), null, AuditAction::SpamMoved, [
                'reason' => $this->reason($verdict),
                'score' => $verdict->score,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return $moved > 0;
    }

    private function reason(SpamVerdict $verdict): ?string
    {
        if (is_null($verdict->reason) || $verdict->reason === '') {
            return null;
        }

        return Str::limit($verdict->reason, self::REASON_LENGTH, '');
    }

    private function store(MailboxMessage $message, ?SpamVerdict $verdict, ?SpamStatus $status): void
    {
        $message->forceFill([
            'spam_checked_at' => now(),
            'spam_reason' => is_null($verdict) ? null : $this->reason($verdict),
            'spam_score' => $verdict?->score,
            'spam_status' => $status,
        ])->save();
    }
}
