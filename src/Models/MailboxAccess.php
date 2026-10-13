<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use J87NL\Mailbox\Enums\AccessLevel;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property string $accessor_type
 * @property int|string $accessor_id
 * @property AccessLevel $level
 * @property-read Mailbox $mailbox
 */
class MailboxAccess extends Model
{
    protected $table = 'mailbox_access';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level' => AccessLevel::class,
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function accessor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
