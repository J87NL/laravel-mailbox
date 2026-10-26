<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use J87NL\Mailbox\Enums\FolderRole;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property string $path
 * @property string $name
 * @property string $delimiter
 * @property FolderRole $role
 * @property array<int, string>|null $flags
 * @property int|null $uid_validity
 * @property int $last_uid
 * @property int $unseen_count
 * @property bool $selectable
 * @property Carbon|null $synced_at
 * @property-read Mailbox $mailbox
 */
class MailboxFolder extends Model
{
    public const DEFAULT_SKIPPED_SPECIAL_USE = [
        '\\All',
        '\\Important',
        '\\Flagged',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'role' => FolderRole::class,
            'selectable' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * @return HasMany<MailboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MailboxMessage::class);
    }

    public function skipsSync(): bool
    {
        if ($this->role === FolderRole::Inbox) {
            return false;
        }

        $skipped = array_map('strtolower', (array) config('mailbox.sync.skip_special_use', self::DEFAULT_SKIPPED_SPECIAL_USE));

        return array_intersect(array_map('strtolower', $this->flags ?? []), $skipped) !== [];
    }

    public function needsSync(int $seconds = 60): bool
    {
        if ($this->skipsSync()) {
            return false;
        }

        return is_null($this->synced_at) || $this->synced_at->lt(now()->subSeconds($seconds));
    }

    public function depth(): int
    {
        return substr_count($this->path, $this->delimiter);
    }
}
