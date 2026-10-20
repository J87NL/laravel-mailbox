# Installation

## Requirements

- PHP 8.4+
- Laravel 11, 12 or 13
- An IMAP server and an SMTP server. UIDPLUS support on the IMAP server is recommended, see
  [Deleting in Trash](message-actions.md#deleting).
- A queue worker, because sync jobs are queued

The package depends on `tranquil-tools/laravel-form-builder` ^1.7 for its forms, `directorytree/imapengine` for IMAP,
`symfony/mailer` for SMTP and `symfony/html-sanitizer` for HTML mail.

## Composer

```bash
composer require j87nl/laravel-mailbox
```

The service provider `J87NL\Mailbox\MailboxServiceProvider` registers automatically via Laravel's package discovery.

### You probably want the UI package as well

For a mailbox screen in your app, install
[`j87nl/laravel-vue-mailbox`](https://github.com/J87NL/laravel-vue-mailbox) instead. It requires this package, so one
`require` installs both:

```bash
composer require j87nl/laravel-vue-mailbox
```

Follow its [installation guide](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/installation.md). Its
`php artisan mailbox:install` publishes the config and migrations of this package, so skip the publish step below. The
other steps on this page still apply, because the UI only calls this package:

| Step on this page                                               | Needed with the UI                                                                                                                                                          |
|-----------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [Publish config and migrations](#publish-config-and-migrations) | Done by `mailbox:install`; you still run `php artisan migrate`                                                                                                              |
| [Bootstrap an admin](#bootstrap-an-admin)                       | Only to create mailboxes on the settings page. That needs `manageMailboxes`, which only admins pass unless your app defines that ability itself. `mailbox:add` always works |
| [Add a mailbox](#add-a-mailbox)                                 | Optional. Admins can add mailboxes and grant access on the settings page                                                                                                    |
| [Schedule the sync](#schedule-the-sync)                         | Yes. The UI shows what the sync stored and queues sync jobs itself                                                                                                          |
| [OAuth mailboxes](#oauth-mailboxes)                             | Only for mailboxes with OAuth authentication                                                                                                                                |

## Publish config and migrations

```bash
php artisan vendor:publish --tag=mailbox-config
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
```

This publishes `config/mailbox.php` and four migrations:

| Migration                                    | Creates                                                                                                   |
|----------------------------------------------|-----------------------------------------------------------------------------------------------------------|
| `create_mailbox_tables`                      | `mailboxes`, `mailbox_folders`, `mailbox_messages`, `mailbox_audit_entries`, `mailbox_access`, `mailbox_message_links` |
| `add_signature_to_mailboxes_table`           | the `signature` column on `mailboxes`                                                                     |
| `create_mailbox_contacts_table`              | `mailbox_contacts`                                                                                        |
| `add_sent_at_index_to_mailbox_messages_table`| an index on `mailbox_messages (mailbox_id, sent_at)`                                                      |

Upgrading from 0.1: publish the migrations again (only the new ones are added) and migrate.

Publish the translations (optional, English and Dutch are included):

```bash
php artisan vendor:publish --tag=mailbox-translations
```

## Bootstrap an admin

Admins get manage access to every mailbox and may create and delete mailboxes. Set at least one, and pick the access
mode:

```dotenv
MAILBOX_ADMINS=you@example.com
MAILBOX_ACCESS_MODE=restricted
```

If your user model is not `App\Models\User`, set `MAILBOX_USER_MODEL`. See [Access control](access.md).

## Add a mailbox

```bash
php artisan mailbox:add
php artisan mailbox:grant {mailbox-id}
```

`mailbox:add` asks for the address, the IMAP and SMTP servers and the credentials, tests the connection and stores the
mailbox. `mailbox:grant` gives a user or group access. See [Artisan commands](artisan-commands.md).

With the UI package, an admin can do both on the
[mailbox settings page](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/pages/settings.md).

## Schedule the sync

Schedule the sync and the body cache pruning, for example in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mailbox:sync')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('mailbox:prune-bodies')->daily();
```

`mailbox:sync` queues a `SyncMailboxJob` per active mailbox, so run a queue worker. Set `MAILBOX_SYNC_QUEUE` to put the
sync jobs on their own queue.

## OAuth mailboxes

A mailbox with `authentication` set to `oauth` uses XOAUTH2 for IMAP and SMTP. The package does not fetch tokens
itself: set `mailbox.oauth_token_provider` to a class that implements `ProvidesAccessToken`. See
[Extension points](extension-points.md#providesaccesstoken).
