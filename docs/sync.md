# Synchronization

The sync mirrors the IMAP folder list and the message headers into the database. Bodies are not synced; they are
fetched when a message is opened (and the raw message is kept for `cache.raw_hours`, see
[Configuration](configuration.md#body-cache)).

## Running the sync

```bash
php artisan mailbox:sync            # queue a SyncMailboxJob per active mailbox
php artisan mailbox:sync 3          # only mailbox 3
php artisan mailbox:sync --now      # run inline instead of queueing
```

Schedule it and run a queue worker:

```php
Schedule::command('mailbox:sync')->everyTwoMinutes()->withoutOverlapping();
```

You can also call the classes directly:

```php
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Jobs\SyncMailboxJob;
use J87NL\Mailbox\Sync\SyncMailbox;

SyncMailboxJob::dispatch($mailbox->id);
SyncFolderJob::dispatch($folder->id);

app(SyncMailbox::class)->handle($mailbox); // inline
```

Both jobs implement `ShouldBeUnique` (per mailbox for 600 seconds, per folder for 120 seconds), run once
(`$tries = 1`), use the queue from `mailbox.sync.queue` and skip inactive mailboxes.

## What a mailbox sync does

`SyncMailbox::handle()`:

1. `SyncFolders` lists the folders on the server and upserts them with their role and flags. Folders that no longer
   exist are deleted, with their cached messages.
2. `SyncFolderMessages` runs for every folder.
3. `synced_at` is set on the mailbox. `last_error` is cleared, or lists the folders that failed with their error.

A folder that fails is reported and skipped; the other folders still sync. When the folder list itself cannot be
fetched, the error is stored in `last_error` and the exception is thrown again. The IMAP connection is closed at the end.

## What a folder sync does

`SyncFolderMessages::handle()` skips folders that are not selectable and runs under a cache lock per folder, so a
scheduled sync and a folder job never sync the same folder at once.

1. **UIDVALIDITY** - when the server reports another `UIDVALIDITY` than the stored one, the cached messages of the folder
   are deleted and `last_uid` starts again at 0.
2. **New messages** - fetches messages with a UID above `last_uid`. On the first sync of a folder (`last_uid` is 0) it
   fetches the messages of the last `sync.initial_days` days instead. It fetches headers, flags, size and body
   structure in chunks of `sync.chunk_size`, never bodies, and does not mark anything as read. Each chunk is stored with
   one upsert.
3. **Known messages** - refreshes the flags of the newest `sync.flag_window` cached messages and deletes cached rows
   that are gone from the server, for example because they were moved in another mail client.
4. **Unseen count** - takes `UNSEEN` from the folder status, or counts the unread cached rows when the server does not
   report it. `synced_at` is set on the folder.

Messages flagged `\Deleted` on the server are treated as gone. A purge on a server without UIDPLUS therefore does not
bring them back, see [Message actions](message-actions.md#deleting).

## Received messages

New messages in a folder can fire the `MessageReceived` event, see [Events](events.md#messagereceived). A message is
only announced when all of these hold:

- the folder has been synced before and was not just reset because of a new `UIDVALIDITY`;
- the folder role is not `Sent`, `Drafts`, `Junk` or `Trash`;
- the `Message-ID` is not yet known in the mailbox, as a cached message in another folder or in the audit trail.

Every new message in a watched folder whose `Message-ID` is not yet known gets a `received` audit entry, also on the
first sync when no event fires. So a message found during the first sync is known, and moving it to another folder
later does not announce it. A message without a `Message-ID` is never known: it is announced again when it shows up in
another watched folder.

## Fetching the folder list on demand

A new mailbox has no folders until its first sync. `SyncFolders::bootstrap()` fetches the folder list inline:

```php
use J87NL\Mailbox\Sync\SyncFolders;

app(SyncFolders::class)->bootstrap($mailbox);
```

It does nothing for an inactive mailbox or a mailbox that already has folders, and runs at most once a minute per
mailbox. Errors are reported, not thrown.

## Other sync triggers

The actions queue a `SyncFolderJob` themselves where it helps:

- after a move, for the target folder (only when something moved);
- after a sent message was stored in Sent;
- when a cached message turned out to be gone on the server (`ForgetMessage`).

Saving a mailbox with `SaveMailbox` queues a `SyncMailboxJob`.

## Not supported

The sync polls. There is no IMAP IDLE worker and no CONDSTORE/QRESYNC support. Flags of messages outside the
`flag_window` are not refreshed.
