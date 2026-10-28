# J87 Laravel Mailbox

Shared IMAP/SMTP mailboxes for Laravel: folder and message sync, move/archive/delete/flag, sending with correct threading headers, and an audit trail per app user. Frontend-agnostic; install `j87nl/laravel-vue-mailbox` for the Vue/Inertia UI. Built on the TranquilTools FormBuilder.

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

The migrations are `create_mailbox_tables`, `add_signature_to_mailboxes_table`, `create_mailbox_contacts_table`, `add_sent_at_index_to_mailbox_messages_table` and `add_conversations_to_mailbox_tables`. Upgrading to 0.4: publish the migrations again, migrate and run `php artisan mailbox:threads` once. Upgrading from 0.1: publish the migrations again (only the new ones are added) and migrate. With the Vue package, `php artisan mailbox:install` does the publishing for you.

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

Schedule the sync and the body cache pruning, and run a queue worker:

```php
Schedule::command('mailbox:sync')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('mailbox:prune-bodies')->daily();
```

`mailbox:prune-bodies` forgets cached bodies and attachment lists fetched more than `cache.body_days` days ago (`--days=` overrides); a message fetches its body again when it is opened. It also removes cached raw messages older than `cache.raw_hours`.

## Configuration

All keys live in `config/mailbox.php`; the ones you are most likely to set in `.env`:

| Key | Env | Default | Purpose |
|---|---|---|---|
| `access.admins` | `MAILBOX_ADMINS` | empty | Comma-separated admin emails |
| `access.mode` | `MAILBOX_ACCESS_MODE` | `restricted` | `open` gives every user write access |
| `connection.timeout` | `MAILBOX_CONNECTION_TIMEOUT` | `10` | Seconds before an IMAP or SMTP connection attempt gives up |
| `connection.read_timeout` | `MAILBOX_CONNECTION_READ_TIMEOUT` | `15` | Seconds an IMAP read waits for the server before the command fails (in a sync job never past 5 seconds before the job timeout) |
| `display_timezone` | `MAILBOX_DISPLAY_TIMEZONE` | `app.timezone` | Timezone for dates the server formats for display (list, reply quote); dates are stored in `app.timezone`, like Laravel's own timestamps |
| `cache.body_days` | `MAILBOX_BODY_CACHE_DAYS` | `30` | Days a fetched body stays cached; `null` never prunes |
| `cache.raw_disk` | `MAILBOX_RAW_CACHE_DISK` | `local` | Private disk for the cached raw messages that serve inline images and attachments |
| `cache.raw_hours` | `MAILBOX_RAW_CACHE_HOURS` | `24` | Hours a raw message stays cached; `null` or `0` fetches every time |
| `compose.editor` | `MAILBOX_COMPOSE_EDITOR` | `hugerte` | Wysiwyg adapter for the compose form and the signature; the toolbar comes from the FormBuilder `email` profile |
| `compose.editor_options` | | HugeRTE basics | Editor options laid over the profile (deep-merged by the renderer); keep it for non-toolbar settings |
| `contacts.providers` | | `[]` | Classes implementing `ProvidesContactSuggestions`, asked for recipient suggestions |
| `contacts.scanned_messages` | | `500` | Recent messages scanned for "seen" addresses in suggestions |
| `sync.queue` | `MAILBOX_SYNC_QUEUE` | default queue | Queue for sync jobs |
| `sync.skip_special_use` | | `\All`, `\Important`, `\Flagged` | Folders with these special-use attributes (Gmail All Mail, Important, Starred) are not synced |
| `sync.time_budget_seconds` | `MAILBOX_SYNC_TIME_BUDGET` | `40` | Seconds a sync job works before it stops and leaves the rest to the next run |
| `sync.job_timeout_seconds` | `MAILBOX_SYNC_JOB_TIMEOUT` | `55` | Timeout of the sync jobs; keep it above the budget and below the queue's `retry_after` |

## Access

`accessMailbox` (read), `workInMailbox` (write) and `manageMailbox` (manage) are Gate abilities per mailbox; `manageMailboxes` is the global ability to create and delete mailboxes, and `accessAnyMailbox` tells whether a user may see the mailbox at all (use it for menu links). They are only defined when the host app has not defined them itself; an app that defines `manageMailboxes` itself gates the menu on `accessAnyMailbox` or `manageMailboxes`. Groups (for example Spatie roles) are configured in `mailbox.access.groups`. `access.user_query` and a group's `query` keep users or groups (for example customers) out of the access candidates.

## Address book and suggestions

Each mailbox has an address book (`mailbox_contacts`, emails stored lowercased and unique per mailbox). Recipient suggestions (`SuggestAddresses`) list, in this order and without duplicates: address-book contacts, the host app's providers from `contacts.providers`, then addresses seen in the mailbox's most recent cached messages (`contacts.scanned_messages`), matched case-insensitively on name and address. A provider implements

```php
interface ProvidesContactSuggestions
{
    /** @return array<int, array{value: string, label: string}> */
    public function suggestions(Mailbox $mailbox, string $query, int $limit): array;
}
```

## Signature

A mailbox can have an HTML signature. It is sanitised on save with the message sanitizer, with `https:` and `data:` images allowed (logos); style declarations that load resources (`url(…)`) are removed. Compose puts it below an empty first paragraph and above the quote, wrapped in `<div class="mailbox-signature">`.

## Quoting

`MessageHtml::quote()` prepares an original message for a reply or forward with the reading pane's sanitiser: body content only, inline styles without `url(…)` and similar, remote images kept as plain `<img>`, and `cid:` images rewritten to a URL you pass plus a `data-mailbox-cid` attribute so the editor can show them. On send, `SendMessage` embeds those images again from the quoted message (`quote_source`) with fresh Content-IDs. It only accepts images marked with `data-mailbox-cid` or pointing at a signed inline URL of that same message (resolved through `ResolvesInlineImageUrls`); anything else that refers to a message part is dropped.

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

The fake records IMAP operations per folder and sent mail on `$connector->transport->sent`; nothing reaches a real server. `Mailbox::factory()`, `MailboxFolder::factory()` and `MailboxMessage::factory()` create rows, see `docs/testing.md`.

## Development

```bash
composer install
composer test
composer analyse
composer format
```
