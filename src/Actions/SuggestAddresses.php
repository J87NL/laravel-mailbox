<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use J87NL\Mailbox\Contracts\ProvidesContactSuggestions;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxContact;
use J87NL\Mailbox\Models\MailboxMessage;

class SuggestAddresses
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function handle(Mailbox $mailbox, string $query, int $limit = 10): array
    {
        $needle = mb_strtolower(trim($query));
        $limit = max(1, $limit);

        return collect([
            ...$this->contacts($mailbox, $needle, $limit),
            ...$this->provided($mailbox, $needle, $limit),
            ...$this->seen($mailbox, $needle, $limit),
        ])
            ->unique(fn (array $suggestion): string => Str::lower($suggestion['value']))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function contacts(Mailbox $mailbox, string $needle, int $limit): array
    {
        $like = $this->like($needle);

        return MailboxContact::query()
            ->where('mailbox_id', $mailbox->id)
            ->when($needle !== '', fn (Builder $query): Builder => $query->where(function (Builder $inner) use ($like): void {
                $inner->where('email', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('organisation', 'like', $like);
            }))
            ->orderBy('name')
            ->orderBy('email')
            ->limit($limit)
            ->get()
            ->map(fn (MailboxContact $contact): array => [
                'label' => $contact->label(),
                'value' => $contact->email,
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function provided(Mailbox $mailbox, string $needle, int $limit): array
    {
        return collect((array) config('mailbox.contacts.providers', []))
            ->map(fn (string $provider): ProvidesContactSuggestions => app($provider))
            ->flatMap(fn (ProvidesContactSuggestions $provider): array => $provider->suggestions($mailbox, $needle, $limit))
            ->reject(fn (array $suggestion): bool => $suggestion['value'] === '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function seen(Mailbox $mailbox, string $needle, int $limit): array
    {
        return $this->recentMessages($mailbox)
            ->flatMap(fn (MailboxMessage $message): array => $this->addressesOf($message))
            ->reject(fn (array $address): bool => mb_strtolower($address['email']) === mb_strtolower($mailbox->email))
            ->filter(fn (array $address): bool => $needle === '' || str_contains(mb_strtolower(sprintf('%s %s', $address['name'], $address['email'])), $needle))
            ->unique(fn (array $address): string => Str::lower($address['email']))
            ->take($limit)
            ->map(fn (array $address): array => [
                'label' => $address['name'] === '' ? $address['email'] : sprintf('%s <%s>', $address['name'], $address['email']),
                'value' => $address['email'],
            ])
            ->values()
            ->all();
    }

    private function like(string $needle): string
    {
        return sprintf('%%%s%%', addcslashes($needle, '%_\\'));
    }

    /**
     * @return Collection<int, MailboxMessage>
     */
    private function recentMessages(Mailbox $mailbox): Collection
    {
        return MailboxMessage::query()
            ->select([
                'from_email',
                'from_name',
                'to',
                'cc',
            ])
            ->where('mailbox_id', $mailbox->id)
            ->orderByDesc('sent_at')
            ->limit(max(1, (int) config('mailbox.contacts.scanned_messages', 500)))
            ->get();
    }

    /**
     * @return array<int, array{email: string, name: string}>
     */
    private function addressesOf(MailboxMessage $message): array
    {
        $from = is_null($message->from_email)
            ? []
            : [
                [
                    'email' => $message->from_email,
                    'name' => (string) $message->from_name,
                ],
            ];

        return array_values(array_filter(
            [
                ...$from,
                ...($message->to ?? []),
                ...($message->cc ?? []),
            ],
            fn (array $address): bool => $address['email'] !== '',
        ));
    }
}
