<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property string $message_id
 * @property float|null $spam_score
 * @property string|null $spam_reason
 * @property Carbon $checked_at
 * @property-read Mailbox $mailbox
 */
class MailboxSpamVerdict extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'spam_score' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
