# Audit trail

Most actions write to `mailbox_audit_entries`, with the model `J87NL\Mailbox\Models\MailboxAuditEntry`. It answers
"who did what to this mail". `MarkMessages::answered()`, `ForgetMessage`, `AddContact` and `EnsureFolder` write no
entry.

## Columns

| Column        | Notes                                                                    |
|---------------|--------------------------------------------------------------------------|
| `mailbox_id`  |                                                                          |
| `user_id`     | The app user, `null` for system actions such as the sync and commands    |
| `action`      | An `AuditAction` enum                                                    |
| `message_id`  | The `Message-ID` header, for message actions                             |
| `subject`     | The subject at the time of the action                                    |
| `folder_path` | The folder the message was in                                            |
| `target_path` | The target folder of a move, or the path of a new folder                 |
| `meta`        | Extra data as an array, depending on the action                          |
| `created_at`  | There is no `updated_at`                                                 |

Relations: `mailbox()`, and `user()` on `mailbox.user_model`.

Entries reference the `Message-ID`, not the cached row. A move gives a message a new UID and a new row, but its history
stays together:

```php
use J87NL\Mailbox\Models\MailboxAuditEntry;

$history = MailboxAuditEntry::query()
    ->where('mailbox_id', $message->mailbox_id)
    ->where('message_id', $message->message_id)
    ->with('user')
    ->latest('created_at')
    ->get();
```

## Actions

`J87NL\Mailbox\Enums\AuditAction`:

| Case             | Value             | Written by                         | Meta                      |
|------------------|-------------------|------------------------------------|---------------------------|
| `Received`       | `received`        | the sync, for new messages         |                           |
| `Sent`           | `sent`            | `SendMessage`                      | `in_reply_to`, `to`: list of addresses |
| `Moved`          | `moved`           | `MoveMessages`                     |                           |
| `Archived`       | `archived`        | `ArchiveMessages`                  |                           |
| `Trashed`        | `trashed`         | `DeleteMessages`, outside Trash    |                           |
| `Deleted`        | `deleted`         | `DeleteMessages`, in Trash         |                           |
| `MarkedRead`     | `marked_read`     | `MarkMessages::read()`             |                           |
| `MarkedUnread`   | `marked_unread`   | `MarkMessages::unread()`           |                           |
| `Flagged`        | `flagged`         | `MarkMessages::flag()`             |                           |
| `Unflagged`      | `unflagged`       | `MarkMessages::unflag()`           |                           |
| `FolderCreated`  | `folder_created`  | `CreateFolder`                     |                           |
| `MailboxCreated` | `mailbox_created` | `SaveMailbox`                      | `changed`: field names    |
| `MailboxUpdated` | `mailbox_updated` | `SaveMailbox`                      | `changed`: field names    |
| `AccessGranted`  | `access_granted`  | `GrantAccess::handle()`            | `accessor`, `level`       |
| `AccessRevoked`  | `access_revoked`  | `GrantAccess::revoke()`            | `accessor`, `level`       |
| `Linked`         | `linked`          | `LinkMessage`, for a new link      | `linkable`                |

`changed` never contains the passwords. `accessor` and `linkable` have the form `morph-class:id`. `to` holds the bare
addresses of the `To` header, also when the recipients were given as one string.

The translations hold a label per action under `mailbox::mailbox.audit_<value>`, for example
`__('mailbox::mailbox.audit_moved')`.

## Writing your own entries

`J87NL\Mailbox\Actions\RecordAudit` is what the actions use:

```php
use J87NL\Mailbox\Actions\RecordAudit;
use J87NL\Mailbox\Enums\AuditAction;

$audit = app(RecordAudit::class);

$audit->forMessages($messages, AuditAction::Moved, $user, targetPath: 'Archive/2026');

$audit->forMessages($messages, AuditAction::Flagged, $user, meta: ['reason' => 'follow-up']);

$audit->forMailbox(
    $mailbox,
    AuditAction::MailboxUpdated,
    $user,
    meta: ['changed' => ['name']],
);
```

`forMessages()` inserts one entry per message in a single query and reads `folder_path` from each message's folder.
Its optional `meta` is stored on every entry.
`forMailbox()` creates one entry and returns it.

## The audit trail and `MessageReceived`

The sync uses the audit trail to decide whether a message is new: a `Message-ID` that already has an entry in the
mailbox is never announced again. See [Synchronization](sync.md#received-messages).

Deleting a mailbox deletes its audit trail.
