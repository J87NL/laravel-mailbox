<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Enums;

enum FolderRole: string
{
    case Archive = 'archive';
    case Custom = 'custom';
    case Drafts = 'drafts';
    case Inbox = 'inbox';
    case Junk = 'junk';
    case Sent = 'sent';
    case Trash = 'trash';

    public static function fromImap(string $path, array $flags): self
    {
        if (strtoupper($path) === 'INBOX') {
            return self::Inbox;
        }

        $map = [
            '\\All' => self::Archive,
            '\\Archive' => self::Archive,
            '\\Drafts' => self::Drafts,
            '\\Junk' => self::Junk,
            '\\Sent' => self::Sent,
            '\\Trash' => self::Trash,
        ];

        foreach ($flags as $flag) {
            if (isset($map[$flag])) {
                return $map[$flag];
            }
        }

        return self::Custom;
    }

    public function label(): ?string
    {
        if ($this === self::Custom) {
            return null;
        }

        return __(sprintf('mailbox::mailbox.role_%s', $this->value));
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Inbox => 0,
            self::Drafts => 1,
            self::Sent => 2,
            self::Archive => 3,
            self::Junk => 4,
            self::Trash => 5,
            self::Custom => 6,
        };
    }
}
