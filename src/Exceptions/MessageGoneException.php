<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Exceptions;

use J87NL\Mailbox\Models\MailboxMessage;
use Throwable;

class MessageGoneException extends MailboxException
{
    public static function for(MailboxMessage $message, ?Throwable $previous = null): self
    {
        return new self(sprintf('Message [%d] is no longer in folder [%s] on the server.', $message->uid, $message->folder->path), 0, $previous);
    }
}
