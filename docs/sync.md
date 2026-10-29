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
(`$tries = 1`), use the queue from `mailbox.sync.queue` and skip inactive mailboxes. They work within a
[time budget](#time-budget), have a `$timeout` of `sync.job_timeout_seconds` (default 55) and `$failOnTimeout = true`.
`mailbox:sync --now` and a direct `SyncMailbox::handle($mailbox)` call have no budget and sync everything in one go.

## Time budget

A large mailbox (a Gmail "All mail" folder with thousands of messages) does not fit in one queue job. The jobs therefore
stop after `sync.time_budget_seconds` seconds (default 40) and leave the rest to the next scheduled run:

- A folder sync has four kinds of steps: `status` (selecting the folder and reading its status), `fetch` (the search
  for new messages plus the first chunk), `chunk` (every further chunk of `sync.chunk_size` messages) and `refresh`
  (one piece of `sync.flag_chunk_size` messages of the flag window). Before each step the sync compares the time left
  with what that step took the last time in this folder (kept in the cache for a day), or `sync.step_reserve_seconds`
  (default 5) when that is more. A measured duration counts for at most half the budget, so one slow outlier (such as
  the first fetch of a folder) does not block that step for good. When the step no longer fits, it is deferred to the next run. So a run ends within the
  budget, and only runs over when a step takes longer than its estimate, by at most that one step. Because the estimate
  is capped at half the budget, a step that really takes longer than that can start with just over half the budget
  left; IMAP reads stop 5 seconds before the job timeout, so such a step fails with a read timeout rather than being
  killed. With the defaults (40 and 55) that moment is 50 seconds into the job, 10 seconds past the budget.
- A folder that is stopped keeps what it stored: `last_uid` is saved after each chunk, so the next run fetches from
  there instead of starting over. Its `synced_at` stays as it was, so it counts as not yet finished.
- The flag refresh goes from the newest message down in pieces of `sync.flag_chunk_size` (default 100). When it is
  stopped, the cache remembers where, and the next run continues there before it starts at the newest message again.
  New messages are fetched completely before the refresh starts, so the folder's `synced_at` is set even when the
  refresh is deferred.
- The first step of a run always runs, so a run makes progress even with a tiny budget.
- A folder whose last chunk was smaller than `sync.chunk_size` counts as fetched completely, also when the budget is
  used up after it.
- A run that stops on a broken IMAP connection (a read timeout or a response it cannot parse, see
  [Configuration](configuration.md#connection)) skips the remaining folders instead of trying them on the same
  connection.
- Nothing is dispatched when a run stops early; the next `mailbox:sync` simply continues.
- Each job that deferred something logs one `info` line, `Mailbox sync deferred work to the next run`, with the mailbox
  (and folder) id and per folder the step that was deferred (`status`, `fetch`, `refresh` or `not reached`). It holds no
  message data.

The folders are synced in this order, so one big backfill cannot keep new mail waiting:

1. the inbox;
2. folders that finished a sync before, the one finished longest ago first. A folder that was stopped or not reached
   keeps its old `synced_at` and comes first in the next run;
3. folders that never finished a sync (a first sync, or a `UIDVALIDITY` reset), oldest folder first. These are the
   backfills: they get what is left of the budget.

To use another budget, set `MAILBOX_SYNC_TIME_BUDGET` and, when needed, `MAILBOX_SYNC_JOB_TIMEOUT`. Keep
budget < job timeout < the `retry_after` of the queue connection (default 90), with room between budget and timeout for
a step that takes longer than before; `connection.read_timeout` (default 15) bounds a step that hangs. A budgeted job
also stops reading from IMAP 5 seconds before `job_timeout_seconds`, so a step that is still waiting then fails with a
read timeout (listed in `last_error`) instead of the worker killing the job. The job's `$timeout` overrides the worker's `--timeout`. The per-folder cache lock of a
budgeted sync expires after `job_timeout_seconds` + 60 seconds (600 without a budget), so a killed job does not keep a
folder locked for long.

You can call the classes with a budget yourself:

```php
use J87NL\Mailbox\Sync\SyncBudget;

app(SyncMailbox::class)->handle($mailbox, SyncBudget::seconds(40));
app(SyncFolderMessages::class)->handle($folder, SyncBudget::fromConfig());
```

## Skipped folders

Gmail shows every label as a folder and adds virtual folders that only repeat mail found elsewhere: All Mail
("[Gmail]/Alle e-mail"), Important and Starred. All Mail holds the whole archive, so its first sync alone searches
years of mail. The sync therefore skips folders with one of the special-use attributes in `sync.skip_special_use`
(default `\All`, `\Important` and `\Flagged`). The attributes are the same in every language, so the folder names do not
matter. The inbox is never skipped.

A skipped folder stays in the folder list with its role (All Mail keeps the archive role, so archiving in Gmail still
moves mail there) and stays selectable, but its messages are not fetched or refreshed, its `synced_at` is not updated
and its unseen count is set to 0. So a folder view of All Mail shows only what was cached before, and the
`SyncFolderJob` that a move into it queues does nothing; archived mail simply leaves the inbox. A skipped folder is not
part of the run's rotation and never shows up in the deferred-work log line. `MailboxFolder::skipsSync()` tells whether a folder is skipped, and `needsSync()` returns `false`
for it, so opening it does not queue a sync. Messages cached before 0.5.3 are left as they are; they are no longer
refreshed or removed. Sent, Drafts, Junk and Trash sync as before.

Set `sync.skip_special_use` to `[]` to sync these folders again. There is no setting per mailbox.

## What a mailbox sync does

`SyncMailbox::handle()`:

1. `SyncFolders` lists the folders on the server and upserts them with their role and flags. Folders that no longer
   exist are deleted, with their cached messages.
2. `SyncFolderMessages` runs for every folder, in the order described under [Time budget](#time-budget), until the
   budget is used up.
3. `synced_at` is set on the mailbox. `last_error` is cleared, or lists the folders that failed with their error.

A folder that fails is reported and skipped; the other folders still sync. An IMAP read timeout is not reported: it is
only listed in `last_error` and stops the run, and the next run tries again. This also holds for a timeout while the
folder list is fetched, for example on `LOGIN`: the job ends without failing. Any other error while the folder list is
fetched is stored in `last_error` and thrown again. A `SyncFolderJob` that runs into a read timeout ends without
failing as well and lists the folder with the error in `last_error`; the next scheduled sync picks the folder up. The IMAP connection is closed at the end.

## What a folder sync does

`SyncFolderMessages::handle()` skips folders that are not selectable or [skipped](#skipped-folders) and runs under a cache lock per folder, so a
scheduled sync and a folder job never sync the same folder at once.

1. **UIDVALIDITY** - when the server reports another `UIDVALIDITY` than the stored one, the cached messages of the folder
   are deleted, `last_uid` starts again at 0 and `synced_at` is cleared until the folder is fetched again completely.
2. **New messages** - fetches messages with a UID above `last_uid`. On the first sync of a folder (`last_uid` is 0) it
   fetches the messages of the last `sync.initial_days` days instead. It fetches headers, flags, size and body
   structure in chunks of `sync.chunk_size`, never bodies, and does not mark anything as read. Each chunk is stored with
   one upsert and moves `last_uid` up. When the [time budget](#time-budget) is used up after a chunk, the folder sync
   stops here and steps 3 and 4 wait for the next run.
3. **Known messages** - refreshes the flags of the newest `sync.flag_window` cached messages and deletes cached rows
   that are gone from the server, for example because they were moved in another mail client.
4. **Unseen count** - takes `UNSEEN` from the folder status, or counts the unread cached rows when the server does not
   report it. `synced_at` is set on the folder.

New messages get their conversation key right after they are stored, see below.

Messages flagged `\Deleted` on the server are treated as gone. A purge on a server without UIDPLUS therefore does not
bring them back, see [Message actions](message-actions.md#deleting).

## Conversations

`J87NL\Mailbox\Threading\ConversationKeys` gives every stored message a `thread_key`. Messages with the same key in
a mailbox form one conversation; the UI package groups the message list by it when the mailbox has
`conversation_view` on. The key is found in this order:

1. **References and In-Reply-To** - the ids in `References` (in order) and `In-Reply-To`. When one of them is a message
   of the same mailbox that already has a key, the message takes that key (the one nearest the start of `References`
   wins). Otherwise the key is the first referenced id, the root of the conversation.
2. **Subject** - a message without those headers whose subject starts with a reply or forward prefix (`Re:`, `Fw:`,
   `Fwd:`, `AW:`, `WG:`, `Antw:`, `Doorst:`, `TR:`, `SV:`, `VS:`, `RV:`, also repeated or numbered like `Re[2]:`) joins
   the newest keyed message of the mailbox from the `threading.subject_days` days (default 90) before the reply's own
   date (or before now, for a message without a date), with the same normalized subject (prefixes removed, whitespace
   collapsed, lower case). The window is relative to the reply, so a backfill of old mail gives the same result as the
   sync did. The database narrows the candidates with a `LIKE` in which every non-ASCII letter is a single-character
   wildcard (SQLite's `lower()` only folds ASCII); the exact comparison happens on the normalized subject in PHP.
3. **Own id** - otherwise its own `Message-ID`, so a first message is the root of its conversation.
4. Without any id: `subject:` followed by the SHA-1 of the normalized subject.

A message without reply headers and without a reply prefix never joins another conversation, so newsletters with the
same subject stay apart. Keys are mailbox-wide, so a reply in Sent and the answer in the Inbox share one.

A reply synced before its parent gets the parent's id as key. When the parent is keyed later and joins another
conversation (its own `In-Reply-To` points further up), the messages of the mailbox keyed with the parent's id move to
that conversation as well. Re-keying does not touch `updated_at`. `php artisan mailbox:threads --fresh` recomputes every key when needed; it
works in batches of 200, each in a transaction.

The sync assigns keys to newly stored messages, oldest first. For messages stored before 0.4, run
`php artisan mailbox:threads` once, see [Artisan commands](artisan-commands.md#mailboxthreads).

## Received messages

New messages in a folder can fire the `MessageReceived` event, see [Events](events.md#messagereceived). A message is
only announced when all of these hold:

- the folder has finished a sync before and was not reset because of a new `UIDVALIDITY` since (a first sync or a
  reset that takes several runs announces nothing);
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
