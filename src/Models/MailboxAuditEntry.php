<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use J87NL\Mailbox\Enums\AuditAction;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property int|string|null $user_id
 * @property AuditAction $action
 * @property string|null $message_id
 * @property string|null $subject
 * @property string|null $folder_path
 * @property string|null $target_path
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property-read Model|null $user
 */
class MailboxAuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'meta' => 'array',
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
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('mailbox.user_model'));
    }
}
