# Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=mailbox-config
```

This creates `config/mailbox.php`. Every key is listed below with its default.

## User model

```php
'user_model' => env('MAILBOX_USER_MODEL', 'App\\Models\\User'),
```

The model of your app users. It is used for the `user` relation of audit entries and message links, and as the "user"
source in the access form and `mailbox:grant`.

## Access

```php
'access' => [
    'mode' => env('MAILBOX_ACCESS_MODE', 'restricted'),
    'admins' => array_filter(explode(',', (string) env('MAILBOX_ADMINS', ''))),
    'resolver' => RelationAccessorResolver::class,
    'user_label' => 'name',
    'groups' => [],
],
```

| Key          | Env                   | Default                    | Description                                                                 |
|--------------|-----------------------|----------------------------|-----------------------------------------------------------------------------|
| `mode`       | `MAILBOX_ACCESS_MODE` | `restricted`               | `open` gives every logged-in user write access to every active mailbox      |
| `admins`     | `MAILBOX_ADMINS`      | empty                      | Comma-separated emails that get manage access everywhere                     |
| `resolver`   |                       | `RelationAccessorResolver` | Class implementing `ResolvesAccessors`                                      |
| `user_label` |                       | `name`                     | User attribute shown in the access form                                     |
| `user_search`|                       | `user_label`, `email`      | User columns the access form searches (optional key)                        |
| `groups`     |                       | `[]`                       | Group models (for example roles) that can be granted access                 |

A group entry looks like this:

```php
'groups' => [
    [
        'model' => Spatie\Permission\Models\Role::class,
        'relation' => 'roles',
        'label' => 'name',
        'prefix' => 'Role',
    ],
],
```

`model` and `relation` are required. `label` defaults to `name` and `prefix` to the class basename of the model. An
optional `search` lists the columns the access form searches, by default only `label`. An empty `user_search` or
`search` falls back to the default. Matching ignores case; on SQLite only for ASCII letters. See
[Access control](access.md).

## Body cache

```php
'cache' => [
    'body_days' => env('MAILBOX_BODY_CACHE_DAYS', 30),
    'raw_disk' => env('MAILBOX_RAW_CACHE_DISK', 'local'),
    'raw_hours' => env('MAILBOX_RAW_CACHE_HOURS', 24),
],
```

`body_days` is how many days a fetched body and attachment list stay cached. `mailbox:prune-bodies` forgets older ones;
a message fetches its body again when it is opened. `null` switches pruning off. See
[Artisan commands](artisan-commands.md#mailboxprune-bodies).

The first fetch of a message also stores the raw message on the `raw_disk` disk (`mailbox/raw/{mailbox id}/{message id}.eml`)
for `raw_hours` hours. Inline images, attachment downloads, forwarded attachments and quoted images are served from that
file instead of fetching the whole message from the server again. Use a private disk: the file holds the complete mail.
Files are written with private visibility. Moving, deleting or forgetting a message, a sync that finds it gone from the
server or a changed UIDVALIDITY, and deleting its mailbox remove the file. `mailbox:prune-bodies` removes expired files
and those of pruned bodies, so schedule it: it also catches files of folders the sync removed. `null` or `0` switches the raw cache off. A published config without these keys uses
the defaults.

## Compose editor

```php
'compose' => [
    'editor' => env('MAILBOX_COMPOSE_EDITOR', 'hugerte'),
    'editor_options' => [
        'hugerte' => [
            'menubar' => false,
            'statusbar' => false,
            'convert_urls' => false,
            'entity_encoding' => 'raw',
            'paste_data_images' => false,
            'min_height' => 320,
            'extended_valid_elements' => 'img[src|alt|title|width|height|style|class|data-mailbox-cid]',
        ],
    ],
],
```

`editor` is the FormBuilder Wysiwyg adapter for the message body and the mailbox signature. It is independent of the
FormBuilder's own `default-editor`. Both editors use the FormBuilder `email` profile for their toolbar.

`editor_options.<editor>` is laid over that profile. Use it for settings other than the toolbar. Keep
`data-mailbox-cid` in `extended_valid_elements` for HugeRTE: quoted inline images need that attribute, see
[Sending mail](sending.md#quoting-and-inline-images). A config published before 0.3.0 lacks this line; add it.

This package only names the editor. The frontend has to install and register it; for the UI package see its
[installation guide](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/installation.md#4-install-the-compose-editor).

## Contacts

```php
'contacts' => [
    'providers' => [],
    'scanned_messages' => 500,
],
```

| Key                | Default | Description                                                                 |
|--------------------|---------|-----------------------------------------------------------------------------|
| `providers`        | `[]`    | Classes implementing `ProvidesContactSuggestions`, asked for recipient suggestions |
| `scanned_messages` | `500`   | How many recent messages are scanned for addresses seen in the mailbox      |

See [Address book & suggestions](contacts.md).

## Connection

```php
'connection' => [
    'read_timeout' => (int) env('MAILBOX_CONNECTION_READ_TIMEOUT', 15),
    'timeout' => (int) env('MAILBOX_CONNECTION_TIMEOUT', 10),
],
```

`timeout` is the number of seconds before an IMAP or SMTP connection attempt gives up. `read_timeout` is the number of
seconds an IMAP read waits for the server to send anything; after that the command fails with a timeout instead of
waiting until the queue worker kills the job. It applies to every IMAP command, also when a message is opened, and
counts silence, not the total time of a command: a large fetch that keeps sending data is not cut off. Keep it well
below `sync.job_timeout_seconds` minus `sync.time_budget_seconds`. In a budgeted sync job a read also never waits past
5 seconds before `sync.job_timeout_seconds`. Values below 1 are raised to 1. A published config
without `read_timeout` uses 15.

## Display timezone

```php
'display_timezone' => env('MAILBOX_DISPLAY_TIMEZONE'),
```

The timezone for dates the server formats for display, such as the date in a reply quote. `null`, or a value that is not
a valid timezone identifier, falls back to `app.timezone`.

The `J87NL\Mailbox\Support\DisplayTime` helper applies it:

```php
use J87NL\Mailbox\Support\DisplayTime;

DisplayTime::timezone();                          // 'Europe/Amsterdam'
DisplayTime::format($message->sent_at, 'd-m-Y H:i'); // '' for null
DisplayTime::local($message->sent_at);            // a copy in the display timezone
DisplayTime::isToday($message->sent_at);          // "today" in the display timezone
```

## Sync

```php
'sync' => [
    'chunk_size' => 50,
    'flag_chunk_size' => 100,
    'flag_window' => 500,
    'initial_days' => 90,
    'job_timeout_seconds' => (int) env('MAILBOX_SYNC_JOB_TIMEOUT', 55),
    'queue' => env('MAILBOX_SYNC_QUEUE'),
    'skip_special_use' => [
        '\\All',
        '\\Important',
        '\\Flagged',
    ],
    'step_reserve_seconds' => (int) env('MAILBOX_SYNC_STEP_RESERVE', 5),
    'time_budget_seconds' => (int) env('MAILBOX_SYNC_TIME_BUDGET', 40),
],
```

| Key                   | Env                        | Default       | Description                                                                 |
|-----------------------|----------------------------|---------------|-----------------------------------------------------------------------------|
| `chunk_size`          |                            | `50`          | Messages fetched and stored per chunk                                       |
| `flag_chunk_size`     |                            | `100`         | Messages of the flag window refreshed per step; a deferred refresh resumes there |
| `flag_window`         |                            | `500`         | The newest known messages per folder whose flags are refreshed              |
| `initial_days`        |                            | `90`          | How far back the first sync of a folder goes                                |
| `job_timeout_seconds` | `MAILBOX_SYNC_JOB_TIMEOUT` | `55`          | `$timeout` of `SyncMailboxJob` and `SyncFolderJob`                          |
| `queue`               | `MAILBOX_SYNC_QUEUE`       | default queue | Queue for `SyncMailboxJob` and `SyncFolderJob`                              |
| `skip_special_use`    |                            | `\All`, `\Important`, `\Flagged` | Folders with one of these special-use attributes are not synced, see [Skipped folders](sync.md#skipped-folders); `[]` syncs them |
| `step_reserve_seconds` | `MAILBOX_SYNC_STEP_RESERVE` | `5`          | Seconds a sync step needs at least before it starts, when it took less the last time |
| `time_budget_seconds` | `MAILBOX_SYNC_TIME_BUDGET` | `40`          | Seconds a sync job spends on folders before it stops and leaves the rest to the next run |

A published config without `flag_chunk_size`, `job_timeout_seconds`, `skip_special_use`, `step_reserve_seconds` or
`time_budget_seconds` uses the defaults. Keep
`time_budget_seconds` < `job_timeout_seconds` < the `retry_after` of the queue connection (Laravel's default is `90`),
with room for a step that takes longer than before between the first two: a job that runs longer than its timeout is killed and
marked failed, and a job that is still running at `retry_after` is handed to a second worker. See
[Time budget](sync.md#time-budget).

See [Synchronization](sync.md).

## Threading

```php
'threading' => [
    'subject_days' => 90,
],
```

| Key | Default | Description |
|---|---|---|
| `subject_days` | `90` | How far back a reply without reply headers looks for a message with the same subject, see [Conversations](sync.md#conversations). A published config without this key uses the default |

## Folders

```php
'folders' => [
    'archive_fallback' => 'Archive',
    'trash_fallback' => 'Trash',
],
```

The folder paths used when a mailbox has no folder with the archive or trash role. The folder is created on the server
when it does not exist. See [Message actions](message-actions.md).

## HTML

```php
'html' => [
    'block_remote_images' => true,
],
```

Remote images and resource-loading styles are removed from rendered mail unless the user asks for them. `false` always
shows them. See [HTML rendering](html.md).

## OAuth token provider

```php
'oauth_token_provider' => null,
```

A class implementing `J87NL\Mailbox\Contracts\ProvidesAccessToken`. Required for mailboxes with `authentication` set to
`oauth`; without it connecting such a mailbox throws a `MailboxException`. See
[Extension points](extension-points.md#providesaccesstoken).

## Environment variables

| Env                          | Key                     | Default             |
|------------------------------|-------------------------|---------------------|
| `MAILBOX_USER_MODEL`         | `user_model`            | `App\Models\User`   |
| `MAILBOX_ACCESS_MODE`        | `access.mode`           | `restricted`        |
| `MAILBOX_ADMINS`             | `access.admins`         | empty               |
| `MAILBOX_BODY_CACHE_DAYS`    | `cache.body_days`       | `30`                |
| `MAILBOX_RAW_CACHE_DISK`     | `cache.raw_disk`        | `local`             |
| `MAILBOX_RAW_CACHE_HOURS`    | `cache.raw_hours`       | `24`                |
| `MAILBOX_COMPOSE_EDITOR`     | `compose.editor`        | `hugerte`           |
| `MAILBOX_CONNECTION_READ_TIMEOUT` | `connection.read_timeout` | `15`        |
| `MAILBOX_CONNECTION_TIMEOUT` | `connection.timeout`    | `10`                |
| `MAILBOX_DISPLAY_TIMEZONE`   | `display_timezone`      | `app.timezone`      |
| `MAILBOX_SYNC_JOB_TIMEOUT`   | `sync.job_timeout_seconds` | `55`             |
| `MAILBOX_SYNC_QUEUE`         | `sync.queue`            | default queue       |
| `MAILBOX_SYNC_STEP_RESERVE`  | `sync.step_reserve_seconds` | `5`             |
| `MAILBOX_SYNC_TIME_BUDGET`   | `sync.time_budget_seconds` | `40`             |
