<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use J87NL\Mailbox\Contracts\ResolvesAccessors;
use J87NL\Mailbox\Enums\AccessLevel;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAccess;

class MailboxAccessResolver
{
    /**
     * @var array<string, AccessLevel|null>
     */
    private array $levels = [];

    public function __construct(
        private readonly ResolvesAccessors $accessors,
    ) {}

    public function allows(?Authenticatable $user, Mailbox $mailbox, AccessLevel $required): bool
    {
        if (is_null($user)) {
            return false;
        }

        if (! $mailbox->active) {
            return $this->isAdmin($user);
        }

        return $this->levelFor($user, $mailbox)?->allows($required) ?? false;
    }

    public function allowsAny(?Authenticatable $user): bool
    {
        if (is_null($user)) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        $activeIds = Mailbox::query()
            ->where('active', true)
            ->pluck('id')
            ->all();

        $this->preload($user, $activeIds);

        return collect($activeIds)->contains(
            fn (int $mailboxId): bool => $this->levels[$this->key($user, $mailboxId)]?->allows(AccessLevel::Read) ?? false,
        );
    }

    public function isAdmin(?Authenticatable $user): bool
    {
        if (is_null($user)) {
            return false;
        }

        $email = data_get($user, 'email');

        if (! is_string($email) || $email === '') {
            return false;
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return false;
        }

        $admins = array_map(fn (string $admin): string => mb_strtolower(trim($admin)), config('mailbox.access.admins', []));

        return in_array(mb_strtolower($email), $admins, true);
    }

    public function levelFor(Authenticatable $user, Mailbox $mailbox): ?AccessLevel
    {
        $this->preload($user, [$mailbox->id]);

        return $this->levels[$this->key($user, $mailbox->id)];
    }

    /**
     * @param  array<int, int>  $mailboxIds
     */
    public function preload(Authenticatable $user, array $mailboxIds): void
    {
        $missing = array_values(array_filter(
            $mailboxIds,
            fn (int $mailboxId): bool => ! array_key_exists($this->key($user, $mailboxId), $this->levels),
        ));

        if ($missing === []) {
            return;
        }

        foreach ($this->resolve($user, $missing) as $mailboxId => $level) {
            $this->levels[$this->key($user, $mailboxId)] = $level;
        }
    }

    public function flush(): void
    {
        $this->levels = [];
    }

    private function key(Authenticatable $user, int $mailboxId): string
    {
        return sprintf('%s:%d', $user->getAuthIdentifier(), $mailboxId);
    }

    /**
     * @param  array<int, int>  $mailboxIds
     * @return array<int, AccessLevel|null>
     */
    private function resolve(Authenticatable $user, array $mailboxIds): array
    {
        if ($this->isAdmin($user)) {
            return array_fill_keys($mailboxIds, AccessLevel::Manage);
        }

        if (config('mailbox.access.mode') === 'open') {
            return array_fill_keys($mailboxIds, AccessLevel::Write);
        }

        $levels = array_fill_keys($mailboxIds, null);
        $accessors = $this->accessors->accessorsFor($user);

        if ($accessors === []) {
            return $levels;
        }

        $granted = MailboxAccess::query()
            ->whereIn('mailbox_id', $mailboxIds)
            ->where(function (Builder $query) use ($accessors): void {
                foreach ($accessors as $accessor) {
                    $query->orWhere(fn (Builder $inner): Builder => $inner
                        ->where('accessor_type', $accessor['type'])
                        ->where('accessor_id', $accessor['id']));
                }
            })
            ->get()
            ->groupBy('mailbox_id')
            ->map(fn (Collection $rows): ?AccessLevel => AccessLevel::highest($rows->pluck('level')->all()))
            ->all();

        return array_replace($levels, $granted);
    }
}
