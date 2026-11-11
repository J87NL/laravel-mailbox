# Upgrading

Steps per version. The [changelog](https://github.com/J87NL/laravel-mailbox/blob/main/CHANGELOG.md) lists every change.

## 0.13.0

Publish the migrations again and migrate: `add_spam_filter_to_mailbox_tables` adds `mailboxes.spam_filter_mode`
(default `off`, also for existing mailboxes) and the `spam_*` columns of `mailbox_messages`,
`create_mailbox_spam_allowlist_table` adds the [spam filter allowlist](spam-filter.md#the-allowlist).

```bash
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
```

With every mailbox on `off`, nothing changes until you pick a mode.

Allowlisted mail is only moved out of the junk folder once `spam.trusted_authserv_ids` names your own mail server, see
[Mail in the junk folder](spam-filter.md#mail-in-the-junk-folder).

`MarkJunk` and `SyncFolderMessages` take a new constructor argument (`SpamFeedback` and `FilterSyncedMessages`). Code
that resolves them from the container needs no change; a test that builds them by hand passes the new argument.

## 0.12.0

- An IMAP connection the server closed (`ImapConnectionClosedException`) during a sync, a `SyncFolderJob` or a
  `FetchOlderMessagesJob` is no longer reported and no longer fails the job: it is listed in `last_error` like a read
  timeout. An error tracker that showed these will stop showing them; `last_error` still does.
- **Behaviour change:** Gmail's All Mail (`\All`) is synced again. The default of `sync.skip_special_use` is now
  `\Important` and `\Flagged`. A published config that still lists `\All` keeps skipping All Mail; remove `\All` there
  to get the new behaviour, or keep it (or add it) to keep the old one. See [Gmail All Mail](sync.md#gmail-all-mail).
- No migration. An All Mail folder that was never synced gets a first sync of the last `sync.initial_days` days, not
  the whole archive; older mail comes in with `FetchOlderMessages`. One that last synced within `sync.initial_days`
  continues from its stored `last_uid`; one that synced longer ago starts over with the windowed first sync.
- Every Gmail message that is also in the inbox or a label is now cached twice, once in All Mail. All Mail does not fire
  `MessageReceived`. Code that counts or lists messages across all folders of a mailbox sees the copies; group by
  `message_id` (as `HasMailboxMessages` does) where that matters.
- `MoveMessages` and `ArchiveMessages` queue a `SyncFolderJob` for All Mail now. A test that asserted no job for All
  Mail sets `sync.skip_special_use` to include `\All` or asserts the job.

## 0.11.1

Archiving Gmail inbox mail moves it to All Mail again. `MailboxConnector::supportsGmailLabels()`,
`MailboxConnector::removeGmailLabel()`, `MailboxConnector::GMAIL_CAPABILITY` and `FakeConnector::$gmailLabelsRemoved`
are removed; a test that read `gmailLabelsRemoved` checks the `move` operation on the inbox instead.
`ArchiveMessages` takes two constructor arguments again; resolve it from the container.

Mail archived from the Gmail inbox with 0.11.0 is still in the Gmail inbox but no longer cached; archive it in Gmail itself.

## 0.11.0

Publish the migrations again and migrate: `create_mailbox_templates_table` adds the
[message templates](templates.md), `add_confirm_delete_to_mailboxes_table` adds `mailboxes.confirm_delete` (default
`false`, also for existing mailboxes).

```bash
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
```

`folders.junk_fallback` is optional in a published config. `ArchiveMessages` takes two more constructor arguments; resolve
it from the container.

## 0.10.0

Publish the migrations again and migrate: `add_message_count_to_mailbox_folders_table` adds
`mailbox_folders.message_count`, `oldest_uid` and `older_exhausted`.

```bash
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
```

The column is filled on the next completed sync of each folder. Until then it is `0`, so nothing looks older. To fetch
mail from before `sync.initial_days`, call `FetchOlderMessages` or queue `FetchOlderMessagesJob`, see
[Older messages](sync.md#older-messages). `sync.older_batch_size` is optional in a published config. A test that
builds `SyncFolderMessages` by hand needs `StoreRemoteMessages` instead of `ConversationKeys`.

## 0.9.0

Nothing to run: no migration and no config publish. The MCP tools now also carry `openWorldHint`; a test in your app
that compares the tool list with its annotations needs the new value. Check where your app serves the mailbox tools:
not next to tools that change things, see [Keep mail away from write tools](mcp.md#keep-mail-away-from-write-tools).

## 0.8.0

Nothing to run: no migration and no config publish.

- **Behaviour change:** a user with `Manage` on an inactive mailbox, from their own access row or one of their groups,
  now passes `manageMailbox` on it. They reach its settings and can set it active again, without an email in
  `MAILBOX_ADMINS`. `accessMailbox` and `workInMailbox` on an inactive mailbox still pass for admins only, so
  `manageMailbox` there no longer implies reading it. Code that shows folders or messages after checking only
  `manageMailbox` must check `accessMailbox` as well. See [Inactive mailboxes](access.md#inactive-mailboxes).
- An app that defines `manageMailbox` itself only to let managers into inactive mailboxes can drop that definition.

## 0.7.1

Nothing to run. An IMAP read timeout while the folder list is fetched (for example on `LOGIN`) and in a `SyncFolderJob`
no longer fails the job or reaches the error tracker. A mailbox sync lists it in `last_error`. The MCP tools now carry
read-only annotations; a test in your app that compares the tool list with its annotations needs the new values.

## 0.7.0

Nothing to run: no migration and no config publish.

- **Behaviour change:** `access.user_query` now also applies to emails in `MAILBOX_ADMINS` and to open mode. A user it
  filters out is no admin, gets nothing in open mode and fails `accessAnyMailbox`, `manageMailboxes` and every mailbox
  ability. An app that added a `Gate::before` to deny these abilities to such users can remove it, as long as it
  does not define one of these abilities itself: an app-defined `manageMailboxes` must apply the filter itself, for
  example with `AccessQuery::admitsUser($user)`. Check that your admins pass the query, or they lose access.
- A group's `prefix` may now be a translation key, for example `'prefix' => 'mailbox.role'`. A plain string still works
  and is shown as it is when no translation exists, see [Users and groups](access.md#users-and-groups).
- With the UI package, gate the menu on `@canany(['accessAnyMailbox', 'manageAnyMailbox'])`, see
  [Abilities](access.md#abilities).

## 0.6.0

Nothing to run: no migration and no config publish. To keep users or groups out of the access candidates, add
`access.user_query` or a group's `query` to your published config, see
[Keeping users and groups out](access.md#keeping-users-and-groups-out). A custom `ResolvesAccessors` that only did that
can be replaced by the default resolver with these keys.

To offer the new MCP tools, install `laravel/mcp` and add `MailboxMcp::tools()` to your MCP server, see
[MCP tools](mcp.md). An app that had its own copies of these tools can drop them; keep a filter in `access.user_query`
for users who must never reach a mailbox.

## 0.5.5

Nothing to run. An IMAP read timeout during a folder sync is no longer reported, only listed in `last_error`.

## 0.5.4

Nothing to run: no migration and no config publish. A budgeted sync job now stops reading from IMAP 5 seconds before
`sync.job_timeout_seconds`.

## 0.5.3

Nothing to run: no migration and no config publish. A published config without the new keys uses the defaults.

- Gmail's All Mail, Important and Starred folders are no longer synced (`sync.skip_special_use`, see
  [Skipped folders](sync.md#skipped-folders)). Their cached messages stay but are no longer refreshed. Set the key to
  `[]` in a published config to keep syncing them.
- IMAP reads now time out after `connection.read_timeout` (15) seconds without data. Raise
  `MAILBOX_CONNECTION_READ_TIMEOUT` when a server is known to stay silent longer on large commands.
- Sync jobs log an `info` line when they defer work to the next run.

## 0.5.1

Nothing to run. Sync jobs work within `sync.time_budget_seconds` (40) and have a 55 second timeout. When you change
either, keep budget < job timeout < the queue's `retry_after`, see [Time budget](sync.md#time-budget).

## 0.5.0

Pass a search url to `new AccessForm($action, $searchUrl)` and add `search()` to a custom `ResolvesAccessors`. Hosts on
`j87nl/laravel-vue-mailbox` only bump both packages.

## 0.4.0

Publish the migrations again, migrate (adds `add_conversations_to_mailbox_tables`) and run `php artisan mailbox:threads`
once.
