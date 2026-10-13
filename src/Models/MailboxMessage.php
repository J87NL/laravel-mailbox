<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property int $mailbox_folder_id
 * @property int $uid
 * @property string|null $message_id
 * @property string|null $in_reply_to
 * @property string|null $references
 * @property string|null $subject
 * @property string|null $from_email
 * @property string|null $from_name
 * @property array<int, array{email: string, name: string}>|null $to
 * @property array<int, array{email: string, name: string}>|null $cc
 * @property array<int, array{email: string, name: string}>|null $reply_to
 * @property Carbon|null $sent_at
 * @property int|null $size
 * @property bool $is_seen
 * @property bool $is_flagged
 * @property bool $is_answered
 * @property bool $has_attachments
 * @property string|null $body_html
 * @property string|null $body_text
 * @property array<int, array{content_id: ?string, content_type: string, filename: ?string, inline: bool}>|null $attachments
 * @property Carbon|null $body_fetched_at
 * @property-read MailboxFolder $folder
 * @property-read Mailbox $mailbox
 */
class MailboxMessage extends Model
{
    protected $guarded = ['id'];

    protected $hidden = [
        'body_html',
        'body_text',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'body_fetched_at' => 'datetime',
            'cc' => 'array',
            'has_attachments' => 'boolean',
            'is_answered' => 'boolean',
            'is_flagged' => 'boolean',
            'is_seen' => 'boolean',
            'reply_to' => 'array',
            'sent_at' => 'datetime',
            'to' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MailboxFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MailboxFolder::class, 'mailbox_folder_id');
    }

    /**
     * @return HasMany<MailboxMessageLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(MailboxMessageLink::class, 'message_id', 'message_id');
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function hasBody(): bool
    {
        return ! is_null($this->body_fetched_at);
    }
}
