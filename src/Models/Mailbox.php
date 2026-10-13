<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use J87NL\Mailbox\Enums\FolderRole;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $from_name
 * @property string $authentication
 * @property string $imap_host
 * @property int $imap_port
 * @property string $imap_encryption
 * @property string $imap_username
 * @property string|null $imap_password
 * @property string $smtp_host
 * @property int $smtp_port
 * @property bool|null $smtp_tls
 * @property string $smtp_username
 * @property string|null $smtp_password
 * @property bool $append_sent
 * @property bool $active
 * @property Carbon|null $synced_at
 * @property string|null $last_error
 */
class Mailbox extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['imap_password', 'smtp_password'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'append_sent' => 'boolean',
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'smtp_tls' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MailboxAccess, $this>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(MailboxAccess::class);
    }

    /**
     * @return HasMany<MailboxAuditEntry, $this>
     */
    public function auditEntries(): HasMany
    {
        return $this->hasMany(MailboxAuditEntry::class);
    }

    /**
     * @return HasMany<MailboxFolder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(MailboxFolder::class);
    }

    /**
     * @return HasMany<MailboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MailboxMessage::class);
    }

    public function folderWithRole(FolderRole $role): ?MailboxFolder
    {
        return $this->folders()->where('role', $role)->first();
    }

    public function usesOAuth(): bool
    {
        return $this->authentication === 'oauth';
    }
}
