# Upgrading

Steps per version. The [changelog](https://github.com/J87NL/laravel-mailbox/blob/main/CHANGELOG.md) lists every change.

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
