# Events

The package dispatches two events. Both are plain classes with the `Dispatchable` trait; register listeners the usual
Laravel way.

## MessageReceived

`J87NL\Mailbox\Events\MessageReceived` fires during the sync for genuinely new mail. It is the hook for automation.

```php
public function __construct(
    public readonly MailboxMessage $message,
) {}
```

The message is the cached row, with its `folder` relation set. The body is not fetched yet; use `FetchMessageBody`
when you need it.

It fires once per `Message-ID` and mailbox, and never:

- on the first sync of a folder, or after a `UIDVALIDITY` reset;
- in folders with the role `Sent`, `Drafts`, `Junk` or `Trash`;
- for a message the mailbox already knows, in another folder or in the audit trail.

A message without a `Message-ID` header is never treated as known, so it is announced again after a move to another
watched folder.

See [Synchronization](sync.md#received-messages) for the details.

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Ticket;
use J87NL\Mailbox\Events\MessageReceived;

class CreateTicket
{
    public function handle(MessageReceived $event): void
    {
        Ticket::query()->create([
            'mailbox_id' => $event->message->mailbox_id,
            'message_id' => $event->message->message_id,
            'subject' => $event->message->subject,
        ]);
    }
}
```

The sync usually runs in a queued job, so the listener runs there too.

## MessageSent

`J87NL\Mailbox\Events\MessageSent` fires after `SendMessage` sent a message.

```php
public function __construct(
    public readonly Mailbox $mailbox,
    public readonly string $messageId,
    public readonly string $subject,
) {}
```

`messageId` is the new `Message-ID` without angle brackets. The event fires after the audit entry is written, also when
storing the copy in Sent failed.
