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

Use the `LinkMessage` action:

```php
use J87NL\Mailbox\Actions\LinkMessage;

$link = app(LinkMessage::class)->handle($message, $customer, $request->user());
```

It creates the link for the message's mailbox and `Message-ID`, stores who made it in `user_id`, and writes a `linked`
[audit entry](audit-trail.md) with `linkable` (`morph-class:id`) in its meta. Linking the same message to the same model
again returns the existing link and writes no new entry. A message without a `Message-ID` header, or a model that is not
saved yet, cannot be linked: the action throws a `MailboxException`. The link and its audit entry are written in one
transaction. The action does not check access; authorize the request first.

Links you create yourself, for example with `MailboxMessageLink::query()->create()` or `$message->links()->create()`,
are not audited. Use `LinkMessage` when the audit trail should show the link.

## From the message side

`MailboxMessage::links()` returns the links with the same mailbox and `Message-ID`, so a copy of the same message in
another mailbox does not share them. This also holds for eager loading, `withCount('links')` and `whereHas('links')`. Creating through the relation fills
both the mailbox and the `Message-ID`:

```php
$message->links()->with('linkable')->get();

$message->links()->create([
    'linkable_type' => $customer->getMorphClass(),
    'linkable_id' => $customer->getKey(),
]);
```

Deleting a mailbox deletes its links.
