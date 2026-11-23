<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use Illuminate\Database\Eloquent\Builder;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Models\MailboxSpamVerdict;

class SpamVerdictStore
{
    public function remember(MailboxMessage $message, ?float $score, ?string $reason): void
    {
        if (is_null($message->message_id) || is_null($message->from_email)) {
            return;
        }

        MailboxSpamVerdict::query()
            ->upsert(
                [
                    [
                        'checked_at' => now(),
                        'from_email' => strtolower($message->from_email),
                        'mailbox_id' => $message->mailbox_id,
                        'message_id' => $message->message_id,
                        'spam_reason' => $reason,
                        'spam_score' => $score,
                    ],
                ],
                [
                    'mailbox_id',
                    'message_id',
                    'from_email',
                ],
                [
                    'checked_at',
                    'spam_reason',
                    'spam_score',
                ],
            );
    }

    /**
     * @param  array<int, int>  $uids
     */
    public function copyTo(MailboxFolder $folder, array $uids): void
    {
        if ($uids === []) {
            return;
        }

        $folder->messages()
            ->whereIn('uid', $uids)
            ->whereNull('spam_checked_at')
            ->whereNotNull('message_id')
            ->whereExists($this->verdictColumn('id'))
            ->update([
                'spam_checked_at' => $this->verdictColumn('checked_at'),
                'spam_reason' => $this->verdictColumn('spam_reason'),
                'spam_score' => $this->verdictColumn('spam_score'),
            ]);
    }

    /**
     * @return Builder<MailboxSpamVerdict>
     */
    private function verdictColumn(string $column): Builder
    {
        $verdicts = new MailboxSpamVerdict;
        $messages = new MailboxMessage;
        $query = MailboxSpamVerdict::query();
        $grammar = $query->getQuery()->getGrammar();

        return $query
            ->select($verdicts->qualifyColumn($column))
            ->whereColumn($verdicts->qualifyColumn('mailbox_id'), $messages->qualifyColumn('mailbox_id'))
            ->whereColumn($verdicts->qualifyColumn('message_id'), $messages->qualifyColumn('message_id'))
            ->whereRaw(sprintf(
                '%s = lower(%s)',
                $grammar->wrap($verdicts->qualifyColumn('from_email')),
                $grammar->wrap($messages->qualifyColumn('from_email')),
            ))
            ->limit(1);
    }
}
