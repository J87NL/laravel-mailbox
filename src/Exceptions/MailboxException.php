<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Exceptions;

use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxMessage;
use RuntimeException;

class MailboxException extends RuntimeException
{
    public static function folderNotFound(Mailbox $mailbox, string $path): self
    {
        return new self("Folder [{$path}] does not exist in mailbox [{$mailbox->name}].");
    }

    public static function missingMessageId(MailboxMessage $message): self
    {
        return new self("Message [{$message->id}] has no Message-ID and cannot be linked.");
    }

    public static function missingTokenProvider(Mailbox $mailbox): self
    {
        return new self("Mailbox [{$mailbox->name}] uses OAuth, but no mailbox.oauth_token_provider is configured.");
    }
}
