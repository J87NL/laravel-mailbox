<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property string|null $name
 * @property string $email
 * @property string|null $organisation
 * @property string|null $notes
 * @property-read Mailbox $mailbox
 */
class MailboxContact extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function label(): string
    {
        if (is_null($this->name) || $this->name === '') {
            return $this->email;
        }

        return sprintf('%s <%s>', $this->name, $this->email);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::lower(trim($value)),
        );
    }
}
