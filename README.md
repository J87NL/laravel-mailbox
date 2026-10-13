# J87 Laravel Mailbox

Shared IMAP/SMTP mailboxes for Laravel: folder and message sync, move/archive/delete/flag, sending with correct threading headers, and an audit trail per app user. Frontend-agnostic; install `j87nl/laravel-vue-mailbox` for the Vue/Inertia UI. Built on the TranquilTools FormBuilder.

Design, security model and open work: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Requirements

- PHP 8.4+, Laravel 11, 12 or 13
- An IMAP server (UIDPLUS recommended, see "Deleting in Trash" below) and an SMTP server

## Installation

```bash
composer require j87nl/laravel-mailbox
php artisan vendor:publish --tag=mailbox-config
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
```

Bootstrap at least one admin and pick the access mode in `.env`:

```dotenv
MAILBOX_ADMINS=you@example.com
MAILBOX_ACCESS_MODE=restricted
```

Add a mailbox and grant access from the command line (the UI package offers the same):

```bash
php artisan mailbox:add
php artisan mailbox:grant {mailbox-id}
```

Schedule the sync and run a queue worker:

```php
Schedule::command('mailbox:sync')->everyTwoMinutes()->withoutOverlapping();
```

## Configuration

All keys live in `config/mailbox.php`; the ones you are most likely to set in `.env`:

| Key | Env | Default | Purpose |
|---|---|---|---|
| `access.admins` | `MAILBOX_ADMINS` | empty | Comma-separated admin emails |
| `access.mode` | `MAILBOX_ACCESS_MODE` | `restricted` | `open` gives every user write access |
| `connection.timeout` | `MAILBOX_CONNECTION_TIMEOUT` | `10` | Seconds before an IMAP or SMTP connection attempt gives up |
| `display_timezone` | `MAILBOX_DISPLAY_TIMEZONE` | `app.timezone` | Timezone for dates the server formats for display (list, reply quote); dates are stored in UTC |
| `compose.editor` | `MAILBOX_COMPOSE_EDITOR` | `hugerte` | Wysiwyg adapter for the compose form |
| `sync.queue` | `MAILBOX_SYNC_QUEUE` | default queue | Queue for sync jobs |

## Access

`accessMailbox` (read), `workInMailbox` (write) and `manageMailbox` (manage) are Gate abilities per mailbox; `manageMailboxes` is the global ability to create and delete mailboxes, and `accessAnyMailbox` tells whether a user may see the mailbox at all (use it for menu links). They are only defined when the host app has not defined them itself. Groups (for example Spatie roles) are configured in `mailbox.access.groups`.

## SMTP security

SMTP always requires TLS: implicit TLS on port 465, mandatory STARTTLS otherwise. A relay without TLS needs `smtp_tls = false` on the mailbox row; the form does not offer this on purpose.

## Deleting in Trash

Purging from Trash uses `UID EXPUNGE`, which removes only the selected messages. Servers without UIDPLUS get the messages flagged `\Deleted` but not expunged, because a plain `EXPUNGE` would also remove mail other clients flagged in the shared mailbox.

## Testing your app

```php
use J87NL\Mailbox\Testing\FakeConnector;

$connector = FakeConnector::install();
$connector->remote($mailbox)->folder('INBOX')->setMessages([...]);
```

The fake records IMAP operations per folder and sent mail on `$connector->transport->sent`; nothing reaches a real server.

## Development

```bash
composer install
composer test
composer analyse
composer format
```
