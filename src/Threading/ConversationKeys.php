<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Threading;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use J87NL\Mailbox\Models\MailboxMessage;

class ConversationKeys
{
    private const REPLY_PREFIX = '/^\s*(?:(?:re|fw|fwd|aw|wg|antw|doorst|tr|sv|vs|rv|r)(?:\[\d+\])?\s*:\s*)+/iu';

    private const CANDIDATES = 50;

    private const KEY_LENGTH = 255;

    private const SUBJECT_DAYS = 90;

    /**
     * @param  iterable<int, MailboxMessage>  $messages
     */
    public function assign(iterable $messages): int
    {
        $assigned = 0;

        foreach ($messages as $message) {
            $message->thread_key = $this->keyFor($message);

            MailboxMessage::query()
                ->whereKey($message->getKey())
                ->toBase()
                ->update([
                    'thread_key' => $message->thread_key,
                ]);

            $message->syncOriginalAttribute('thread_key');
            $this->adoptEarlierReplies($message);
            $assigned++;
        }

        return $assigned;
    }

    public function keyFor(MailboxMessage $message): string
    {
        $parents = $this->parents($message);

        if ($parents !== []) {
            return $this->limit($this->knownKey($message, $parents) ?? $parents[0]);
        }

        if ($this->isReply($message->subject)) {
            $bySubject = $this->keyBySubject($message);

            if (! is_null($bySubject)) {
                return $bySubject;
            }
        }

        $own = $this->bare($message->message_id);

        if (! is_null($own)) {
            return $this->limit($own);
        }

        return sprintf('subject:%s', sha1(self::normalizeSubject($message->subject)));
    }

    private function adoptEarlierReplies(MailboxMessage $message): void
    {
        $own = $this->bare($message->message_id);

        if (is_null($own) || $own === $message->thread_key) {
            return;
        }

        MailboxMessage::query()
            ->where('mailbox_id', $message->mailbox_id)
            ->where('thread_key', $this->limit($own))
            ->toBase()
            ->update([
                'thread_key' => $message->thread_key,
            ]);
    }

    public static function normalizeSubject(?string $subject): string
    {
        $stripped = (string) preg_replace(self::REPLY_PREFIX, '', (string) $subject);

        return Str::lower(Str::squish($stripped));
    }

    private function isReply(?string $subject): bool
    {
        return preg_match(self::REPLY_PREFIX, (string) $subject) === 1;
    }

    /**
     * @return list<string>
     */
    private function parents(MailboxMessage $message): array
    {
        preg_match_all('/<([^<>\s]+)>/', (string) $message->references, $matches);

        $ids = [
            ...$matches[1],
            $this->bare($message->in_reply_to),
        ];
        $own = $this->bare($message->message_id);

        return collect($ids)
            ->filter(fn (?string $id): bool => ! is_null($id) && $id !== '' && $id !== $own)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $parents
     */
    private function knownKey(MailboxMessage $message, array $parents): ?string
    {
        $variants = collect($parents)
            ->flatMap(fn (string $id): array => [
                $id,
                sprintf('<%s>', $id),
            ])
            ->all();

        /** @var Collection<string, string> $known */
        $known = MailboxMessage::query()
            ->where('mailbox_id', $message->mailbox_id)
            ->whereKeyNot($message->getKey())
            ->whereIn('message_id', $variants)
            ->whereNotNull('thread_key')
            ->pluck('thread_key', 'message_id')
            ->mapWithKeys(fn (string $key, string $id): array => [$this->bare($id) => $key]);

        foreach ($parents as $parent) {
            if ($known->has($parent)) {
                return $known->get($parent);
            }
        }

        return null;
    }

    private function keyBySubject(MailboxMessage $message): ?string
    {
        $normalized = self::normalizeSubject($message->subject);

        if ($normalized === '') {
            return null;
        }

        $sentAt = $message->sent_at ?? now();
        $words = preg_split('/\s+/u', Str::squish((string) preg_replace(self::REPLY_PREFIX, '', (string) $message->subject))) ?: [];
        $pattern = sprintf('%%%s%%', implode('%', array_map($this->likeWord(...), $words)));

        $match = MailboxMessage::query()
            ->where('mailbox_id', $message->mailbox_id)
            ->whereKeyNot($message->getKey())
            ->whereNotNull('thread_key')
            ->whereBetween('sent_at', [
                $sentAt->copy()->subDays($this->subjectDays()),
                $sentAt,
            ])
            ->whereRaw("lower(mailbox_messages.subject) like ? escape '!'", [$pattern])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::CANDIDATES)
            ->get(['id', 'subject', 'thread_key'])
            ->first(fn (MailboxMessage $candidate): bool => self::normalizeSubject($candidate->subject) === $normalized);

        return $match?->thread_key;
    }

    private function likeWord(string $word): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($word));

        return (string) preg_replace('/[^\x00-\x7F]/u', '_', $escaped);
    }

    private function subjectDays(): int
    {
        $days = config('mailbox.threading.subject_days', self::SUBJECT_DAYS);

        return is_numeric($days) ? (int) $days : self::SUBJECT_DAYS;
    }

    private function bare(?string $id): ?string
    {
        $trimmed = trim((string) $id, " \t\r\n<>");

        return $trimmed === '' ? null : $trimmed;
    }

    private function limit(string $key): string
    {
        return Str::limit($key, self::KEY_LENGTH, '');
    }
}
