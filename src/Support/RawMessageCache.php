<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxMessage;

class RawMessageCache
{
    private const DIRECTORY = 'mailbox/raw';

    public function enabled(): bool
    {
        return ($this->hours() ?? 0) > 0;
    }

    public function get(MailboxMessage $message): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $disk = $this->disk();
        $path = $this->path($message);
        $lastModified = rescue(fn (): int => $disk->lastModified($path), null, report: false);

        if (is_null($lastModified)) {
            return null;
        }

        if ($this->expired($lastModified)) {
            $disk->delete($path);

            return null;
        }

        $raw = rescue(fn (): ?string => $disk->get($path), null, report: false);

        return is_null($raw) || $raw === '' ? null : $raw;
    }

    public function put(MailboxMessage $message, string $raw): void
    {
        if (! $this->enabled() || $raw === '') {
            return;
        }

        $disk = $this->disk();
        $path = $this->path($message);
        $temporary = sprintf('%s.%s.tmp', $path, Str::random(16));

        rescue(function () use ($disk, $path, $raw, $temporary): void {
            $disk->put($temporary, $raw, 'private');
            $disk->move($temporary, $path);
        }, function () use ($disk, $temporary): void {
            rescue(fn (): bool => $disk->delete($temporary), report: false);
        });
    }

    /**
     * @param  Collection<int, MailboxMessage>  $messages
     */
    public function forget(Collection $messages): void
    {
        if ($messages->isEmpty()) {
            return;
        }

        $this->disk()->delete($messages
            ->map(fn (MailboxMessage $message): string => $this->path($message))
            ->values()
            ->all());
    }

    /**
     * @param  Builder<MailboxMessage>  $query
     */
    public function forgetMatching(Builder $query): void
    {
        $query
            ->select([
                'id',
                'mailbox_id',
            ])
            ->chunkById(500, function (EloquentCollection $messages): void {
                $this->forget($messages);
            });
    }

    public function forgetMailbox(Mailbox $mailbox): void
    {
        $this->disk()->deleteDirectory(sprintf('%s/%d', self::DIRECTORY, $mailbox->getKey()));
    }

    public function pruneExpired(): int
    {
        $disk = $this->disk();

        return collect($disk->allFiles(self::DIRECTORY))
            ->filter(fn (string $path): bool => $this->expired(rescue(fn (): int => $disk->lastModified($path), PHP_INT_MAX, report: false)))
            ->each(fn (string $path): bool => $disk->delete($path))
            ->count();
    }

    private function expired(int $lastModified): bool
    {
        $hours = $this->hours();

        if (is_null($hours) || $hours === 0) {
            return true;
        }

        return $lastModified < now()->subHours($hours)->getTimestamp();
    }

    private function hours(): ?int
    {
        $hours = config('mailbox.cache.raw_hours', 24);

        if (is_null($hours) || $hours === '') {
            return null;
        }

        return max(0, (int) $hours);
    }

    private function path(MailboxMessage $message): string
    {
        return sprintf('%s/%d/%d.eml', self::DIRECTORY, $message->mailbox_id, $message->getKey());
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('mailbox.cache.raw_disk', 'local'));
    }
}
