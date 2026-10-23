# Message actions

The actions in `J87NL\Mailbox\Actions` change mail. Resolve them from the container. Each one sends its IMAP command
first; the cache update and the audit entry follow (moves and purges write the audit entry before they delete the
cached rows). When an IMAP command throws, the cache update and the audit entry for those messages do not happen.

The actions do not check access. Authorize first, see [Access control](access.md).

The `$user` argument is the app user the audit entry is recorded against. Pass `null` for system actions.

## Moving

```php
use J87NL\Mailbox\Actions\MoveMessages;

$moved = app(MoveMessages::class)->handle($messages, $targetFolder, $request->user());
```

`handle(Collection $messages, MailboxFolder $target, ?Authenticatable $user, AuditAction $action = AuditAction::Moved): int`

- Messages already in the target folder are skipped.
- One `UID MOVE` is sent per source folder.
- The cached rows are deleted. A `SyncFolderJob` for the target folder is queued when anything moved, so the messages
  reappear there with their new UIDs.
- Returns the number of moved messages.

## Archiving

```php
use J87NL\Mailbox\Actions\ArchiveMessages;

app(ArchiveMessages::class)->handle($mailbox, $messages, $request->user());
```

Moves the messages to the folder with the `Archive` role. Without one, it uses the folder at
`mailbox.folders.archive_fallback` (`Archive`) and creates it on the server when needed. The audit action is
`archived`.

## Deleting

```php
use J87NL\Mailbox\Actions\DeleteMessages;

$count = app(DeleteMessages::class)->handle($mailbox, $messages, $request->user());
```

- Messages **outside** Trash are moved to Trash (the `Trash` role, or `mailbox.folders.trash_fallback`). The audit
  action is `trashed`.
- Messages **in** Trash are purged: they are flagged `\Deleted`, then `UID EXPUNGE` removes exactly those UIDs. The
  cached rows are deleted and the audit action is `deleted`.

`UID EXPUNGE` needs the UIDPLUS extension (RFC 4315). On a server without it the messages are flagged `\Deleted` but not
expunged, because a plain `EXPUNGE` would also remove mail that another client flagged in the shared mailbox. The sync
treats `\Deleted` messages as gone, so they stay hidden until another client or the server expunges them.

## Marking and flagging

```php
use J87NL\Mailbox\Actions\MarkMessages;

$mark = app(MarkMessages::class);

$mark->read($messages, $user);
$mark->readInBackground($messages, $user);
$mark->unread($messages, $user);
$mark->flag($messages, $user);
$mark->unflag($messages, $user);
$mark->answered($message); // no audit entry
```

One `UID STORE` per folder. `read()` and `unread()` keep the `unseen_count` of the folder in step: it changes by the
number of messages whose seen state actually changed, and never drops below zero.

`readInBackground()` updates the rows, the `unseen_count` and the audit trail right away, like `read()`, but sets the
`\Seen` flag on the server after the response has been sent (`FlagRemoteMessagesJob`, dispatched after the response).
Opening a message then does not wait for an IMAP round trip. The job runs in the same process once the response is
sent, without a queue worker; meant for HTTP requests (in a command it runs when the command ends). A failure is
reported, not thrown, and the next sync shows the message as unread again. Until the job ran, a sync of that folder can
still see the message as unread on the server.

## Creating folders

```php
use J87NL\Mailbox\Actions\CreateFolder;

$folder = app(CreateFolder::class)->handle($mailbox, 'Invoices', $parentFolder, $request->user());
```

`handle(Mailbox $mailbox, string $name, ?MailboxFolder $parent, ?Authenticatable $user): MailboxFolder`

Creates the folder on the server (below `$parent` when given, using its delimiter), stores it with the `Custom` role and
writes a `folder_created` audit entry. The [FolderForm](forms.md#folderform) validates the name and parent.

`EnsureFolder::handle(Mailbox $mailbox, FolderRole $role, string $fallbackPath)` returns the folder with a role, or the
folder at the fallback path, and creates it when neither exists. Archive, delete and send use it.

## Reading a message

`FetchMessageBody` loads the body of a cached message without marking it read on the server:

```php
use J87NL\Mailbox\Actions\FetchMessageBody;

$message = app(FetchMessageBody::class)->handle($message);

$message->body_html;
$message->body_text;
$message->attachments; // [['content_id' => ..., 'content_type' => ..., 'filename' => ..., 'inline' => ...], ...]
```

The body is fetched once and cached until `mailbox:prune-bodies` forgets it. Opening a message therefore does not mark
it as read for the other users; call `MarkMessages::read()` when you want that.

Other methods. The first one fetches the whole message from the server and keeps the raw message for
`cache.raw_hours` hours (see [Configuration](configuration.md#body-cache)); later calls read it from there, so a message
with many inline images costs one fetch. Concurrent requests for the same message wait for that one fetch:

| Method                                              | Returns                                                     |
|-----------------------------------------------------|-------------------------------------------------------------|
| `attachment(MailboxMessage $message, int $index)`   | The attachment at that index of `attachments`, or `null`    |
| `attachmentsAt(MailboxMessage $message, array $indexes)` | An attachment (or `null`) per index                    |
| `inlineAttachments(MailboxMessage $message)`        | Attachments with a Content-ID, keyed by that id             |
| `inlineAttachment(MailboxMessage $message, string $contentId)` | One attachment by Content-ID, or `null`          |

The attachments are `DirectoryTree\ImapEngine\Attachment` objects with `contents()`, `filename()` and
`contentType()`.

### Messages that are gone

When the message no longer exists on the server, for example because another client moved it, each method above fails
when it has to reach the server (`handle()` returns an already cached body without contacting it): it throws a
`J87NL\Mailbox\Exceptions\MessageGoneException`. Remove the stale row with `ForgetMessage`, which deletes it and queues a
sync of its folder:

```php
use J87NL\Mailbox\Actions\ForgetMessage;
use J87NL\Mailbox\Exceptions\MessageGoneException;

try {
    $message = app(FetchMessageBody::class)->handle($message);
} catch (MessageGoneException) {
    app(ForgetMessage::class)->handle($message);

    return back();
}
```

`MessageGoneException` extends `MailboxException`, which is also thrown when a folder no longer exists on the server or
when an OAuth mailbox has no token provider.

## Saving a mailbox

`SaveMailbox` creates or updates a mailbox after testing the connection:

```php
use J87NL\Mailbox\Actions\SaveMailbox;

$mailbox = app(SaveMailbox::class)->handle(null, $validated, $request->user());          // create
$mailbox = app(SaveMailbox::class)->handle($mailbox, $validated, $request->user());      // update
$mailbox = app(SaveMailbox::class)->handle($mailbox, $validated, $request->user(), test: false);
```

- The `signature` is sanitized, see [Sending mail](sending.md#signature).
- On update, an empty `imap_password` or `smtp_password` keeps the stored password.
- For mailboxes that do not use OAuth, the password must be entered again when a connection field of that protocol
  changes: `imap_host`, `imap_port`, `imap_encryption` or `imap_username` for IMAP, and `smtp_host`, `smtp_port`,
  `smtp_tls` or `smtp_username` for SMTP.
- For OAuth mailboxes, only users with `manageMailboxes` may change any of these connection fields.
- Unless `test` is `false`, `TestConnection` logs in over IMAP, lists the folders and starts an SMTP session.
- Failures throw a `ValidationException` and nothing is stored. A failed connection test reports on `imap_host` or
  `smtp_host`; the password and OAuth checks report on the fields concerned.
- On success it writes a `mailbox_created` or `mailbox_updated` audit entry (with the changed fields, never the
  passwords) and queues a `SyncMailboxJob`.

`TestConnection` can also be used on its own:

```php
use J87NL\Mailbox\Actions\TestConnection;

$test = app(TestConnection::class);
$result = $test->handle($mailbox); // ['imap' => ?string, 'smtp' => ?string, 'folders' => int]

$test->passes($result);
```

## Address book

`AddContact` adds an address to the address book, see [Address book & suggestions](contacts.md).

## Links

`LinkMessage` links a message to a model of your app, see [Linking messages to models](message-links.md).
