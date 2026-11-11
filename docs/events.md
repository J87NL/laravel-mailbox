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
- in folders with the role `Sent`, `Drafts`, `Junk` or `Trash`, or in Gmail's All Mail (`\All`);
- for a message the mailbox already knows, in another folder (other than All Mail) or in the audit trail.

One exception: with the [spam filter](spam-filter.md#after-the-sync) on, a message that comes back from the junk folder
through "not spam" and was never announced before is announced once in the folder it lands in.

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

The package listens to it itself: for a mailbox whose `spam_filter_mode` is not `off`, `QueueSpamCheck` queues a
`CheckSpamJob` for the message, see [Spam filter](spam-filter.md). Your listener runs before that job, so a new message
has no spam verdict yet, and a message the filter later moves to the junk folder has already been through your
listener.

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
