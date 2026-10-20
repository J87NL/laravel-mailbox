# Linking messages to models

Messages can be linked to any model of your app, such as a customer, an order or a ticket. A link is a row in
`mailbox_message_links` with the model `J87NL\Mailbox\Models\MailboxMessageLink`.

| Column          | Notes                                                |
|-----------------|------------------------------------------------------|
| `mailbox_id`    |                                                      |
| `message_id`    | The `Message-ID` header of the message               |
| `linkable_type`, `linkable_id` | The linked model                      |
| `user_id`       | Optional: who made the link                          |

A link is unique per mailbox, `Message-ID` and model. Like the audit trail it uses the `Message-ID`, so it survives
moves between folders.

## The trait

Add `HasMailboxMessages` to the model:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use J87NL\Mailbox\Concerns\HasMailboxMessages;

class Customer extends Model
{
    use HasMailboxMessages;
}
```

It adds two methods:

```php
$customer->mailboxMessageLinks;            // MorphMany of MailboxMessageLink
$customer->mailboxMessages()->get();       // Builder of MailboxMessage, newest first
```

`mailboxMessages()` returns the cached messages whose mailbox and `Message-ID` match a link. When the same message is in
more than one folder of a mailbox, it is returned once (the row with the lowest id). It is a query builder, not a
relation, so call `get()` or `paginate()` on it.

## Creating a link

The package has no action for this. Create the row yourself:

```php
use J87NL\Mailbox\Models\MailboxMessageLink;

MailboxMessageLink::query()->firstOrCreate([
    'mailbox_id' => $message->mailbox_id,
    'message_id' => $message->message_id,
    'linkable_type' => $customer->getMorphClass(),
    'linkable_id' => $customer->getKey(),
], [
    'user_id' => $request->user()?->getAuthIdentifier(),
]);
```

A message without a `Message-ID` header cannot be linked. The `linked` audit action exists for your own entries, see
[Audit trail](audit-trail.md#writing-your-own-entries).

## From the message side

`MailboxMessage::links()` returns the links with the same `Message-ID`. It matches on the `Message-ID` only, not on the
mailbox, so filter on `mailbox_id` when the same message can be in several mailboxes:

```php
$message->links()->where('mailbox_id', $message->mailbox_id)->with('linkable')->get();
```

Deleting a mailbox deletes its links.
