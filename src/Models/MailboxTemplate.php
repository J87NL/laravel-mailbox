<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property string $name
 * @property string|null $subject
 * @property string $body
 * @property-read Mailbox $mailbox
 */
class MailboxTemplate extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
