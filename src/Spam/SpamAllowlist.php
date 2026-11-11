<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use J87NL\Mailbox\Enums\SpamAllowlistSource;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxSpamAllowlistEntry;

class SpamAllowlist
{
    public function add(?Mailbox $mailbox, string $pattern, SpamAllowlistSource $source = SpamAllowlistSource::Manual): MailboxSpamAllowlistEntry
    {
        $normalized = MailboxSpamAllowlistEntry::normalizePattern($pattern);

        if ($normalized === '') {
            throw new InvalidArgumentException('A spam allowlist pattern needs an email address or a domain.');
        }

        return MailboxSpamAllowlistEntry::query()
            ->firstOrCreate([
                'mailbox_id' => $mailbox?->id,
                'pattern' => $normalized,
            ], [
                'source' => $source,
            ]);
    }

    public function remove(?Mailbox $mailbox, string $pattern): bool
    {
        return MailboxSpamAllowlistEntry::query()
            ->where('mailbox_id', $mailbox?->id)
            ->where('pattern', MailboxSpamAllowlistEntry::normalizePattern($pattern))
            ->delete() > 0;
    }

    public function matches(Mailbox $mailbox, ?string $email): bool
    {
        return $this->allowedSenders($mailbox, collect([$email]))->isNotEmpty();
    }

    /**
     * @param  Collection<int, string|null>  $emails
     * @return Collection<int, string>
     */
    public function allowedSenders(Mailbox $mailbox, Collection $emails): Collection
    {
        $senders = $emails
            ->filter()
            ->map(fn (string $email): string => MailboxSpamAllowlistEntry::normalizePattern($email))
            ->filter()
            ->unique()
            ->values();

        if ($senders->isEmpty()) {
            return collect();
        }

        $patterns = MailboxSpamAllowlistEntry::query()
            ->where(function (Builder $query) use ($mailbox): void {
                $query->where('mailbox_id', $mailbox->id)
                    ->orWhereNull('mailbox_id');
            })
            ->whereIn('pattern', $senders->merge($senders->map(fn (string $email): string => $this->domain($email)))->filter()->unique()->values()->all())
            ->pluck('pattern')
            ->all();

        return $senders
            ->filter(fn (string $email): bool => in_array($email, $patterns, true) || ($this->domain($email) !== '' && in_array($this->domain($email), $patterns, true)))
            ->values();
    }

    private function domain(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '';
        }

        return Str::afterLast($email, '@');
    }
}
