# Artisan commands

## `mailbox:add`

Adds a shared mailbox after testing its IMAP and SMTP connection:

```bash
php artisan mailbox:add
```

The command asks for:

- the email address and the authentication (`plain` username and password, or OAuth 2.0);
- the username (default: the email address) and, for `plain`, the password. The same username and password are used
  for IMAP and SMTP;
- whether to store sent mail in the Sent folder (turn this off for Gmail and Microsoft 365);
- the sender name (default: `app.name`) and the display name (default: the email address);
- the IMAP encryption (`ssl` or `starttls`), host and port (default `993`);
- the SMTP host and port (default `587`).

It saves through `SaveMailbox`, so the connection is tested first and a sync is queued. On failure it prints the errors
and stores nothing. On success it prints the id of the new mailbox. No audit user is recorded.

To use a different SMTP username, edit the mailbox later with the [MailboxForm](forms.md#mailboxform) and
`SaveMailbox`. `smtp_tls` is in neither; set it in the database or from code.

## `mailbox:grant`

Grants a user or group access to a mailbox:

```bash
php artisan mailbox:grant {mailbox}
```

| Argument  | Description    |
|-----------|----------------|
| `mailbox` | The mailbox id |

It offers a search over everything the `ResolvesAccessors` binding lists as assignable, then the level (read, read and
work, manage). An existing row for that accessor is updated. Lowering the last manage row of the mailbox fails with an
error. See [Access control](access.md).

## `mailbox:sync`

Synchronizes folders and messages of the active mailboxes:

```bash
php artisan mailbox:sync
php artisan mailbox:sync 3
php artisan mailbox:sync --now
```

| Argument / option | Description                                              |
|-------------------|----------------------------------------------------------|
| `mailbox`         | Only this mailbox id (optional)                          |
| `--now`           | Run the sync inline instead of queueing a `SyncMailboxJob`, without a time budget |

Inactive mailboxes are skipped, also when their id is given. Schedule it:

```php
Schedule::command('mailbox:sync')->everyTwoMinutes()->withoutOverlapping();
```

See [Synchronization](sync.md).

## `mailbox:threads`

Gives stored messages without a conversation key their key, oldest first, in batches of 200:

```bash
php artisan mailbox:threads          # every mailbox
php artisan mailbox:threads 3        # only mailbox 3
php artisan mailbox:threads --fresh  # recompute every key, also those already set
```

Run it once after upgrading to 0.4. The sync keys new messages itself. See [Conversations](sync.md#conversations).

## `mailbox:prune-bodies`

Forgets cached message bodies and attachment lists, and the cached raw messages of those bodies. A message fetches its
body again when it is opened. It also removes raw messages older than `mailbox.cache.raw_hours`, also when body pruning
is switched off.

```bash
php artisan mailbox:prune-bodies
php artisan mailbox:prune-bodies --days=7
```

| Option   | Description                                                          |
|----------|----------------------------------------------------------------------|
| `--days` | Drop bodies fetched longer ago than this many days. Defaults to `mailbox.cache.body_days` (`30`) |

When the option is not given and `mailbox.cache.body_days` is `null`, the command keeps the bodies. Schedule it:

```php
Schedule::command('mailbox:prune-bodies')->daily();
```

## `mailbox:install`

This command belongs to the UI package `j87nl/laravel-vue-mailbox`, not to this package. It publishes the config and
migrations of this package together with the UI files. See its
[Artisan commands](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/artisan-commands.md).
