# J87 Laravel Mailbox

Shared IMAP/SMTP mailboxes for Laravel: folder and message sync, move/archive/delete/flag, sending with correct threading headers, and an audit trail per app user. Frontend-agnostic; install `j87nl/laravel-vue-mailbox` for the Vue/Inertia UI. Built on the TranquilTools FormBuilder.

Design, security model and open work: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Requirements

- PHP 8.3+, Laravel 11, 12 or 13
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

## Access

`accessMailbox` (read), `workInMailbox` (write) and `manageMailbox` (manage) are Gate abilities per mailbox; `manageMailboxes` is the global ability to create and delete mailboxes. They are only defined when the host app has not defined them itself. Groups (for example Spatie roles) are configured in `mailbox.access.groups`.

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
