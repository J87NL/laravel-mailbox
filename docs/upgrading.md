# Upgrading

Steps per version. The [changelog](https://github.com/J87NL/laravel-mailbox/blob/main/CHANGELOG.md) lists every change.

## 0.6.0

Nothing to run: no migration and no config publish. To keep users or groups out of the access candidates, add
`access.user_query` or a group's `query` to your published config, see
[Keeping users and groups out](access.md#keeping-users-and-groups-out). A custom `ResolvesAccessors` that only did that
can be replaced by the default resolver with these keys.

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
