# Laravel Mailbox

A Laravel package for shared IMAP/SMTP mailboxes inside an existing application. Every authorized user of the app
sees the same mailbox, and every action is recorded against the app user who performed it.

The package is frontend-agnostic. It contains the models, the IMAP sync, the actions, the access rules, the audit
trail, HTML sanitizing and the forms (built with the TranquilTools FormBuilder). It registers no routes and no pages.

## How the packages fit together

The mailbox comes in two packages that lean on each other:

| Package                                                                     | Does                                                                                                                                           |
|-----------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------|
| `j87nl/laravel-mailbox` (this package)                                      | Models, migrations, config, IMAP sync, actions, sending, access abilities, audit trail, HTML sanitizing, forms, translations, Artisan commands |
| [`j87nl/laravel-vue-mailbox`](https://github.com/J87NL/laravel-vue-mailbox) | Routes, controllers, tables and Vue/Inertia pages that call this package, plus `mailbox:install` and the signed inline image route             |

The boundary rule: **what talks to the mail server, stores data or decides access belongs here; what a user clicks
belongs in the UI package.**

Which one to install:

- **You want a mailbox screen in your app.** Install `j87nl/laravel-vue-mailbox`. It requires this package, so you
  get both. Start with its
  [installation guide](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/installation.md).
- **You only sync, send or automate from code** (listeners, jobs, your own UI). Install this package alone.

These docs cover the core. Pages, routes, tables and the frontend are documented in the
[laravel-vue-mailbox docs](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/index.md).

### Related packages

`j87nl/laravel-mailing` reads bounces through its own `MailboxReader` contract (`mailing.mailbox_reader`). This
package does not implement that contract, so there is no built-in bridge between the two. See the
[laravel-mailing bounce docs](https://github.com/J87NL/laravel-mailing/blob/main/docs/delivery/bounces.md).

## Key features

- **Sync** - folders and message headers are mirrored to the database; IMAP stays the source of truth
- **Lazy bodies** - a message body and its attachment list are fetched on first open and cached; the raw message is
  kept for a day so inline images and attachments need no new fetch
- **Actions** - move, archive, delete, mark read or unread, flag and create folders; the IMAP command runs first,
  then the cache and the audit trail follow
- **Sending** - SMTP per mailbox with `In-Reply-To` and `References` headers, a copy in Sent and forwarded attachments
- **Access control** - read, write and manage levels per mailbox for users and groups, as Gate abilities
- **Audit trail** - who did what to which message, keyed on the `Message-ID` header
- **Safe HTML** - mail is sanitized, remote images and tracking styles are blocked by default
- **Address book** - contacts per mailbox and recipient suggestions from several sources
- **Links** - tie messages to any model of your app
- **Fakes** - an IMAP and SMTP fake for your own tests

## How it works

1. Add a mailbox with `php artisan mailbox:add` (or on the
   [settings page](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/pages/settings.md) of the UI package).
   The IMAP and SMTP connection is tested before anything is stored.
2. Grant users or groups access with `php artisan mailbox:grant {mailbox}`.
3. Schedule `mailbox:sync`. It queues a job per mailbox that mirrors the folders and fetches new message headers.
4. Your app (or the UI package's controllers) reads `MailboxFolder` and `MailboxMessage` rows and calls the actions,
   such as `MoveMessages` or `SendMessage`. Each action talks to the IMAP or SMTP server first, then updates the cache,
   then writes an audit entry.
5. Listen to `MessageReceived` to automate on incoming mail.

## Quick example

React to incoming mail and archive it in a listener:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use J87NL\Mailbox\Actions\ArchiveMessages;
use J87NL\Mailbox\Events\MessageReceived;

class ArchiveNewsletters
{
    public function __construct(
        private readonly ArchiveMessages $archive,
    ) {}

    public function handle(MessageReceived $event): void
    {
        $message = $event->message;

        if (! str_ends_with((string) $message->from_email, '@newsletter.example.com')) {
            return;
        }

        $this->archive->handle($message->mailbox, collect([$message]), null);
    }
}
```

Check access with the Gate abilities the package defines:

```php
use Illuminate\Support\Facades\Gate;

Gate::authorize('workInMailbox', $mailbox);
```

## Next steps

- [Installation](installation.md)
- [Configuration](configuration.md)
- [Synchronization](sync.md)
- [Access control](access.md)
- [Message actions](message-actions.md)
- [Sending mail](sending.md)
