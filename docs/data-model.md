# Data model

IMAP is the source of truth. The database is a cache that makes lists, sorting and search fast, plus the data that only
exists in your app: access rows, the audit trail, contacts and links.

All models live in `J87NL\Mailbox\Models`.

## Mailbox

Table `mailboxes`. One row per shared mailbox.

| Column            | Type             | Notes                                                                    |
|-------------------|------------------|--------------------------------------------------------------------------|
| `name`            | string           | Display name                                                             |
| `email`           | string           | The address mail is sent from                                            |
| `from_name`       | string, nullable | Sender name                                                              |
| `signature`       | text, nullable   | Sanitized HTML, see [Sending mail](sending.md#signature)                 |
| `authentication`  | string           | `plain` (default) or `oauth`                                             |
| `imap_host`       | string           |                                                                          |
| `imap_port`       | int              | Default `993`                                                            |
| `imap_encryption` | string           | `ssl` (default) or `starttls`                                            |
| `imap_username`   | string           |                                                                          |
| `imap_password`   | text, nullable   | `encrypted` cast, hidden from arrays                                     |
| `smtp_host`       | string           |                                                                          |
| `smtp_port`       | int              | Default `587`                                                            |
| `smtp_tls`        | bool, nullable   | See [Security](security.md#smtp-requires-tls)                            |
| `smtp_username`   | string           |                                                                          |
| `smtp_password`   | text, nullable   | `encrypted` cast, hidden from arrays                                     |
| `append_sent`     | bool             | Store a copy of sent mail in Sent. Default `true`                        |
| `conversation_view` | bool           | Show the message list as conversations, see [Conversations](sync.md#conversations). Default `false` |
| `active`          | bool             | Inactive mailboxes are not synced and only visible to admins             |
| `synced_at`       | timestamp, nullable | Last completed sync                                                     |
| `last_error`      | text, nullable   | The last sync error, or the folders that failed                          |

Relations: `folders()`, `messages()`, `accesses()`, `contacts()`, `auditEntries()`.

Helpers:

```php
$mailbox->folderWithRole(FolderRole::Trash); // ?MailboxFolder
$mailbox->usesOAuth();                        // true when authentication is 'oauth'
```

Because the passwords use Laravel's `encrypted` cast, rotating `APP_KEY` means entering them again.

## MailboxFolder

Table `mailbox_folders`. A mirror of the IMAP folder list.

| Column         | Notes                                                                       |
|----------------|-----------------------------------------------------------------------------|
| `path`         | The path as the server sends it (modified UTF-7), unique per mailbox        |
| `name`         | The decoded name                                                            |
| `delimiter`    | The hierarchy delimiter                                                     |
| `role`         | A `FolderRole` enum                                                         |
| `flags`        | The IMAP folder flags, as an array                                          |
| `uid_validity` | Sync cursor                                                                 |
| `last_uid`     | Sync cursor: the highest UID fetched                                        |
| `unseen_count` | Number of unread messages                                                   |
| `selectable`   | `false` for `\Noselect` and `\NonExistent` folders                          |
| `synced_at`    | Last completed message sync of this folder                                  |

Relations: `mailbox()`, `messages()`.

```php
$folder->needsSync();    // true when never synced or synced more than 60 seconds ago
$folder->needsSync(300); // custom age in seconds
$folder->depth();        // number of delimiters in the path
```

### FolderRole

`J87NL\Mailbox\Enums\FolderRole` has the cases `Inbox`, `Drafts`, `Sent`, `Archive`, `Junk`, `Trash` and `Custom`.
The role comes from the RFC 6154 special-use flags of the folder:

| Flag                  | Role      |
|-----------------------|-----------|
| path `INBOX`          | `Inbox`   |
| `\All`, `\Archive`    | `Archive` |
| `\Drafts`             | `Drafts`  |
| `\Junk`               | `Junk`    |
| `\Sent`               | `Sent`    |
| `\Trash`              | `Trash`   |
| anything else         | `Custom`  |

`label()` returns a translated label (`null` for `Custom`) and `sortOrder()` a number for ordering folders: inbox,
drafts, sent, archive, junk, trash, then custom folders.

## MailboxMessage

Table `mailbox_messages`. The header cache, one row per folder and UID.

| Column              | Notes                                                                      |
|---------------------|----------------------------------------------------------------------------|
| `mailbox_id`        | The mailbox                                                                |
| `mailbox_folder_id` | The folder                                                                 |
| `uid`               | The IMAP UID, unique per folder                                            |
| `message_id`        | The `Message-ID` header without angle brackets                             |
| `in_reply_to`       | First `In-Reply-To` id                                                     |
| `references`        | The raw `References` header                                                |
| `thread_key`        | Conversation key, see [Conversations](sync.md#conversations); `null` until threaded |
| `subject`           |                                                                            |
| `from_email`, `from_name` |                                                                      |
| `to`, `cc`, `reply_to` | Arrays of `['email' => ..., 'name' => ...]`                             |
| `sent_at`           | The `Date` header, converted to `app.timezone`                             |
| `size`              | Size in bytes                                                              |
| `is_seen`, `is_flagged`, `is_answered` | Flags                                                   |
| `has_attachments`   | From the body structure                                                    |
| `body_html`, `body_text` | Fetched on first open, hidden from arrays                             |
| `attachments`       | Array of `content_id`, `content_type`, `filename`, `inline`                |
| `body_fetched_at`   | When the body was fetched; `null` means not cached                         |

Relations: `mailbox()`, `folder()`, `links()`.

`hasBody()` tells whether the body is cached. Use `FetchMessageBody` to load it, see
[Message actions](message-actions.md#reading-a-message).

String headers longer than their column are cut to fit.

Local rows are disposable. A move gives a message a new UID in another folder, so the package deletes the old row and
the next sync of the target folder creates a new one. Audit entries and links therefore point at the `Message-ID`, not
at the row id.

## MailboxAccess

Table `mailbox_access`. Polymorphic access rows: `accessor_type`, `accessor_id` and a `level` cast to
`AccessLevel`. Unique per mailbox and accessor. Relations: `mailbox()`, `accessor()`. See [Access control](access.md).

## MailboxAuditEntry

Table `mailbox_audit_entries`. Who did what, see [Audit trail](audit-trail.md).

## MailboxContact

Table `mailbox_contacts`. The address book of a mailbox, see [Address book & suggestions](contacts.md).

## MailboxMessageLink

Table `mailbox_message_links`. Polymorphic links from a `Message-ID` to a model of your app, see
[Linking messages to models](message-links.md).

## Deleting a mailbox

All tables reference `mailboxes` with a cascading foreign key. Deleting a `Mailbox` row removes its folders, cached
messages, audit trail, access rows, contacts and links. Mail on the server is untouched.
