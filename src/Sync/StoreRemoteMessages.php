<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Sync;

use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Threading\ConversationKeys;

class StoreRemoteMessages
{
    private const STRING_LENGTH = 255;

    private const TEXT_BYTES = 65535;

    private const UPDATED_COLUMNS = [
        'cc',
        'from_email',
        'from_name',
        'has_attachments',
        'in_reply_to',
        'is_answered',
        'is_flagged',
        'is_seen',
        'message_id',
        'references',
        'reply_to',
        'sent_at',
        'size',
        'subject',
        'to',
    ];

    public function __construct(
        private readonly ConversationKeys $conversations,
    ) {}

    public function query(FolderInterface $remote): MessageQueryInterface
    {
        return $remote->messages()
            ->leaveUnread()
            ->withHeaders()
            ->withFlags()
            ->withSize()
            ->withBodyStructure();
    }

    /**
     * @param  Collection<int, MessageInterface>  $messages
     * @return array<int, int>
     */
    public function handle(MailboxFolder $folder, Collection $messages): array
    {
        $messages = $messages
            ->reject(fn (MessageInterface $message): bool => $this->isDeleted($message))
            ->values();

        if ($messages->isEmpty()) {
            return [];
        }

        $uids = $messages->map(fn (MessageInterface $message): int => $message->uid())->values()->all();

        $existing = $folder->messages()
            ->whereIn('uid', $uids)
            ->pluck('uid')
            ->all();

        MailboxMessage::query()
            ->upsert(
                $messages->map(fn (MessageInterface $message): array => $this->attributes($folder, $message))->values()->all(),
                [
                    'mailbox_folder_id',
                    'uid',
                ],
                self::UPDATED_COLUMNS,
            );

        $this->conversations->assign($folder->messages()
            ->whereIn('uid', $uids)
            ->whereNull('thread_key')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get());

        return array_values(array_diff($uids, $existing));
    }

    /**
     * @param  array<int, string>  $flags
     * @return array{is_answered: bool, is_flagged: bool, is_seen: bool}
     */
    public function flagAttributes(array $flags): array
    {
        return [
            'is_answered' => in_array(ImapFlag::Answered->value, $flags, true),
            'is_flagged' => in_array(ImapFlag::Flagged->value, $flags, true),
            'is_seen' => in_array(ImapFlag::Seen->value, $flags, true),
        ];
    }

    public function isDeleted(MessageInterface $message): bool
    {
        return in_array(ImapFlag::Deleted->value, $message->flags(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(MailboxFolder $folder, MessageInterface $message): array
    {
        $from = $message->from();

        return [
            'cc' => json_encode($this->addresses($message->cc())),
            'from_email' => $this->limit($from?->email()),
            'from_name' => $this->limit($from?->name()),
            'has_attachments' => count($message->bodyStructure()?->attachments() ?? []) > 0,
            'in_reply_to' => $this->limit($message->inReplyTo()[0] ?? null),
            'mailbox_folder_id' => $folder->id,
            'mailbox_id' => $folder->mailbox_id,
            'message_id' => $this->limit($message->messageId()),
            'references' => $this->limitBytes($message->header('References')?->getRawValue()),
            'reply_to' => json_encode($this->addresses($message->addresses('Reply-To'))),
            'sent_at' => $message->date()?->setTimezone((string) config('app.timezone')),
            'size' => $message->size(),
            'subject' => $this->limit($message->subject()),
            'to' => json_encode($this->addresses($message->to())),
            'uid' => $message->uid(),
            ...$this->flagAttributes($message->flags()),
        ];
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array{email: string, name: string}>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): array => [
            'email' => $address->email(),
            'name' => $address->name(),
        ], $addresses));
    }

    private function limit(?string $value): ?string
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        return Str::limit($value, self::STRING_LENGTH, '');
    }

    private function limitBytes(?string $value): ?string
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        return mb_strcut($value, 0, self::TEXT_BYTES);
    }
}
