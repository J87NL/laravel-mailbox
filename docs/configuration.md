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

`model` and `relation` are required. `label` defaults to `name` and `prefix` to the class basename of the model. See
[Access control](access.md).

## Body cache

```php
'cache' => [
    'body_days' => env('MAILBOX_BODY_CACHE_DAYS', 30),
],
```

How many days a fetched body and attachment list stay cached. `mailbox:prune-bodies` forgets older ones; a message
fetches its body again when it is opened. `null` switches pruning off. See
[Artisan commands](artisan-commands.md#mailboxprune-bodies).

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
    'timeout' => (int) env('MAILBOX_CONNECTION_TIMEOUT', 10),
],
```

Seconds before an IMAP or SMTP connection attempt gives up. Values below 1 are raised to 1.

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
    'flag_window' => 500,
    'initial_days' => 90,
    'queue' => env('MAILBOX_SYNC_QUEUE'),
],
```

| Key            | Env                  | Default       | Description                                                       |
|----------------|----------------------|---------------|-------------------------------------------------------------------|
| `chunk_size`   |                      | `50`          | Messages fetched and stored per chunk                             |
| `flag_window`  |                      | `500`         | The newest known messages per folder whose flags are refreshed    |
| `initial_days` |                      | `90`          | How far back the first sync of a folder goes                      |
| `queue`        | `MAILBOX_SYNC_QUEUE` | default queue | Queue for `SyncMailboxJob` and `SyncFolderJob`                    |

See [Synchronization](sync.md).

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
| `MAILBOX_COMPOSE_EDITOR`     | `compose.editor`        | `hugerte`           |
| `MAILBOX_CONNECTION_TIMEOUT` | `connection.timeout`    | `10`                |
| `MAILBOX_DISPLAY_TIMEZONE`   | `display_timezone`      | `app.timezone`      |
| `MAILBOX_SYNC_QUEUE`         | `sync.queue`            | default queue       |
