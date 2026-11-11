<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use J87NL\Mailbox\Enums\SpamAllowlistSource;

/**
 * @property int $id
 * @property int|null $mailbox_id
 * @property string $pattern
 * @property SpamAllowlistSource $source
 * @property-read Mailbox|null $mailbox
 */
class MailboxSpamAllowlistEntry extends Model
{
    protected $table = 'mailbox_spam_allowlist';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => SpamAllowlistSource::class,
        ];
    }

    public static function normalizePattern(string $pattern): string
    {
        return ltrim(Str::lower(trim($pattern)), '@');
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    /**
     * @return Attribute<string, string>
     */
    protected function pattern(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => self::normalizePattern($value),
        );
    }
}
