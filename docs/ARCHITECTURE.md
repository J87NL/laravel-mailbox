# J87 Mailbox — architecture and handoff

Status: **v0.1, tested against fakes**. A J87 package that builds on the TranquilTools FormBuilder and TableBuilder; changes it needs in those packages are in `HANDOFF-tranquil-tools.md`. Both packages install standalone, pass Pest (Testbench), PHPStan (Larastan, level 5) and Pint. Verified against the installed `directorytree/imapengine` 1.25, `laravel-form-builder` 1.6, `laravel-vue-form-builder` 1.9 and `laravel-vue-table-builder` 1.8. Nothing has run against a real IMAP server or inside a host app yet.

## Goal

A shared IMAP/SMTP mailbox inside an existing Laravel app. Every authorised app user sees the same mailbox, can read, send, move, archive and delete, and every action is attributed to the app user who performed it. The differentiator versus a plain webmail is that mail lives next to the app's own domain: messages can be linked to app models, and the audit trail answers "who did what to this mail".

## Package split

The split mirrors the TranquilTools FormBuilder: a frontend-agnostic core and a renderer. Namespaces are `J87NL\Mailbox` and `J87NL\VueMailbox`.

| Package | Contains | Depends on |
|---|---|---|
| `j87nl/laravel-mailbox` | Models, migration, IMAP sync, actions (move/archive/delete/flag/send), audit trail, HTML sanitising, `ComposeForm` and `FolderForm`, IMAP/SMTP fakes for tests (`J87NL\Mailbox\Testing`) | `imapengine`, `laravel-form-builder` (core only), `symfony/mailer`, `symfony/html-sanitizer` |
| `j87nl/laravel-vue-mailbox` | Routes, controllers, `MessagesTable`, Inertia pages and Vue components | core, `laravel-vue-form-builder`, `laravel-vue-table-builder`, Inertia |

Forms live in the core because `laravel-form-builder` is frontend-agnostic. **Tables cannot**: the TableBuilder has no agnostic PHP layer (`AbstractTable` lives in `laravel-vue-table-builder`). A future React or Livewire renderer would therefore have to redefine the message table. Extracting `laravel-table-builder` the same way the form core was extracted would fix this, and is worth doing for its own sake.

## Data model

- `mailboxes` — one row per shared mailbox; IMAP/SMTP credentials with `encrypted` casts; `authentication` is `plain` or `oauth`.
- `mailbox_folders` — mirror of the IMAP folder list with a `role` (inbox, sent, drafts, archive, junk, trash, custom) derived from RFC 6154 special-use flags, plus `uid_validity` and `last_uid` as sync cursors and `synced_at` for the last completed message sync.
- `mailbox_messages` — header cache per folder + UID, including `reply_to` (used as reply recipient) and `sent_at` normalised to the app timezone. Body, text and attachment metadata are fetched lazily on first open and cached (`body_fetched_at`). Folder paths are stored as the server sends them (modified UTF-7); names are stored decoded.
- `mailbox_audit_entries` — who did what, keyed on the RFC `Message-ID` header.
- `mailbox_message_links` — polymorphic links from a message to any app model (`HasMailboxMessages` trait on the model).

**Key decision:** audit entries and links reference the `Message-ID` header, not the local row id. A move in IMAP gives the message a new UID in another folder, so local rows are disposable cache; the `Message-ID` survives moves and is what ties history and links together.

## Sync model

IMAP is the source of truth; the database is a cache that makes lists, sorting and search fast.

1. `SyncFolders` lists folders and upserts them; folders that disappeared are deleted.
2. `SyncFolderMessages` per folder:
   - compares `UIDVALIDITY`; on change the folder cache is dropped and refetched;
   - fetches new messages from `last_uid + 1` (first run: last `initial_days` days) with headers, flags, size and body structure — no bodies — and stores each chunk with one upsert; string headers are cut to their column length;
   - refreshes flags for the newest `flag_window` known UIDs with one grouped update per flag combination, and deletes rows that vanished remotely (handled in Thunderbird or a phone);
   - treats messages flagged `\Deleted` as gone, so a purge on a server without UIDPLUS does not bring them back;
   - runs under a cache lock per folder, so a scheduled sync and a folder job never sync the same folder at once.
   A folder that fails is reported and skipped; the other folders still sync, and `mailboxes.last_error` lists the failed folders.
4. `MessageReceived` is the hook for app automation, so it fires only for genuinely new mail:
   - never on the first sync of a folder or after a `UIDVALIDITY` reset (those rebuild the cache, they do not receive mail); the messages found then do get a `received` audit entry, so they are known and never announced later;
   - never in folders with role sent, drafts, junk or trash;
   - only when the `Message-ID` is not already known in that mailbox, either as a cached message in another folder or in the audit trail (moves, sends and earlier receipts are all audited). Each announced message gets a `received` audit entry, so it is announced once.
3. Triggers: opening a mailbox that has no folders yet fetches the folder list inline (cheap; a failure shows a "being synchronised" state instead of a 404), `mailbox:sync` (schedule it, e.g. every two minutes), `SyncFolderJob` when a folder is opened and its `synced_at` is older than a minute, and after a move for the target folder (only when something moved). Jobs are `ShouldBeUnique`.

Not yet done: IMAP IDLE worker, CONDSTORE/QRESYNC for cheap flag sync, flag refresh beyond the window.

## Action semantics

Every action is IMAP-first, then updates the cache, then writes an audit entry.

- **Move**: `UID MOVE`, delete local rows, queue sync of the target folder (the message reappears there with its new UID).
- **Archive**: move to the folder with role `archive`, or create `folders.archive_fallback`.
- **Delete**: outside Trash → move to Trash. Inside Trash → `\Deleted` on our UIDs, then `UID EXPUNGE` of exactly those UIDs (UIDPLUS, RFC 4315). Without UIDPLUS nothing is expunged: a plain `EXPUNGE` would also remove mail another client flagged `\Deleted` in the shared mailbox. Our messages stay flagged and hidden until another client or the server expunges them.
- **Read/unread/flag**: `UID STORE` on the selected UIDs.
- **Send**: Symfony `EsmtpTransport` built per mailbox (XOAUTH2 when `oauth`), own `Message-ID` (stored without angle brackets, like synced messages), `In-Reply-To` and `References` on replies, `APPEND` to Sent unless `append_sent` is off (Gmail and Microsoft 365 already store a copy), original marked `\Answered`, and a sync of the Sent folder is queued so the copy shows up at once. Once SMTP accepted the message, a failing `APPEND` or `\Answered` is reported (`report()`) but the send still counts as done, so nobody resends.

## Security

- HTML mail is sanitised with `symfony/html-sanitizer`; remote images are stripped unless the user clicks "show images"; `cid:` images are rewritten to short-lived signed URLs before sanitising (the sanitizer drops `cid:` itself), and only the app host is allowed as image host while remote images are blocked.
- While remote images are blocked, style declarations that can load resources (`url(`, `image-set(`, CSS escapes, `@import`) are removed as well, so `background: url(https://tracker)` does not leak.
- The sanitised HTML is rendered in an iframe via `srcdoc` with a CSP meta tag and `sandbox` without `allow-scripts`. The CSP `img-src` follows the same flag: `data:` plus the app origin when blocked, `* data:` when the user allowed images.
- Attachments download as `application/octet-stream` with `nosniff`; only common image types are served inline.
- Access runs through Gate abilities per mailbox (`accessMailbox`, `workInMailbox`, `manageMailbox`) plus `accessAnyMailbox` and `manageMailboxes`; see "Who may see what". The default mode is `restricted`: only access rows and admins grant access. Apps override any ability by defining it themselves.
- SMTP requires TLS (implicit TLS on port 465, otherwise STARTTLS is mandatory), so a man-in-the-middle cannot strip STARTTLS and read the password. The only opt-out is `smtp_tls = false` on the mailbox row, which is not in the form on purpose (CLI or database only), for a local relay without TLS.
- Opening a message that another client moved or deleted removes the cached row, queues a folder sync and returns to the folder with a message; attachments of such a message answer 404.
- Credentials use Laravel's `encrypted` cast, so rotating `APP_KEY` requires re-entering them.

## Reuse of TranquilTools

| Part | Reused | Notes |
|---|---|---|
| Compose form, folder form | FormBuilder (`Text`, `Wysiwyg`, `Files`, `Hidden`, `Select`) | Validation through `AbstractForm::validate()` |
| Message list | TableBuilder (`AbstractTable`, global search, sorting, pagination, `rowLink`, bulk actions with `signedParameters`) | Bulk actions use the `before` callback so a whole selection is one IMAP command |
| Message view, folder sidebar, HTML frame | Custom | Not something a form or table can express |

Unread rows are bold via the TableBuilder's existing `rowClass` prop on the Vue component. Everything the mailbox needs from TranquilTools but which does not exist yet is described in `HANDOFF-tranquil-tools.md`.

## Mailbox management and access

### Where mailboxes are configured

- **UI**: `/mailbox/settings` lists the mailboxes the user may manage; creating and deleting requires the global `manageMailboxes` ability, editing and access management require `manageMailbox` on that mailbox. The form is `J87NL\Mailbox\Forms\MailboxForm` (FormBuilder), the list is `MailboxesTable`.
- **CLI**: `php artisan mailbox:add` (interactive) and `php artisan mailbox:grant {mailbox}`.
- **Connection test**: `SaveMailbox` runs `TestConnection` (IMAP login + folder list, SMTP EHLO + auth) before anything is stored; failures come back as validation errors on `imap_host` / `smtp_host`. Empty password fields on edit keep the stored password, except when the host, port, encryption or username of that protocol changes: then the password must be entered again, so a manager cannot redirect the stored password to another server. For OAuth mailboxes only admins (`manageMailboxes`) may change host, port, encryption or username, because the connection test would send the access token there; the CLI is trusted.
- Deleting a mailbox removes the local cache, audit trail and access rows; mail on the server is untouched. Deactivating (`active` off) hides it for everyone except admins and stops sync.

### Who may see what

Three levels per mailbox, stored in `mailbox_access` as polymorphic rows (`accessor_type`, `accessor_id`, `level`):

| Level | Gate ability | Allows |
|---|---|---|
| `read` | `accessMailbox` | View folders, messages and attachments. Opening a message does **not** mark it read for others. |
| `write` | `workInMailbox` | Also send, reply, forward, move, archive, delete, flag, mark (un)read, create folders. |
| `manage` | `manageMailbox` | Also edit the mailbox settings and its access list. |

An accessor is a user or any group model of the host app. The highest level over the user's own row and all their groups wins.

- **Groups** are configured in `mailbox.access.groups`, e.g. Spatie roles: `['model' => Spatie\Permission\Models\Role::class, 'relation' => 'roles', 'label' => 'name', 'prefix' => 'Rol']`. The default `RelationAccessorResolver` reads that relation on the user; apps with another group concept bind their own `ResolvesAccessors`.
- **Admins**: emails in `MAILBOX_ADMINS` (comma-separated) get `manage` on every mailbox and the global `manageMailboxes` ability. When the user model implements `MustVerifyEmail`, the email must be verified. This is the bootstrap: without at least one admin nobody can create a mailbox via the UI (the CLI always works).
- **Open mode**: `MAILBOX_ACCESS_MODE=open` gives every authenticated user `write` on every active mailbox, without access rows. Handy for small apps such as a volunteer group; the default is `restricted`.
- **Menu**: `accessAnyMailbox` is true for admins and for users with at least read on one active mailbox; use it to show the mailbox menu link. `mailbox.index` is gated with it.
- **Override**: every ability is only defined if the host app has not defined it already, so an app can take over authorisation completely through its own Gate definitions.
- Grants and revocations are written to the audit trail.
- The access form lists every user and group the resolver knows (built once per request). Every manager therefore sees all user names; a server-side searchable select is HANDOFF item 11.
- The last `manage` row of a mailbox cannot be revoked or lowered; the attempt returns a validation error on `accessor`.
- An inactive mailbox is visible to admins only.

## Open work (before a first tag)

- [x] Pest feature tests for sync (new messages, UIDVALIDITY change, remote delete, flag change, `\Deleted`), every action, sending (reply headers, failing `APPEND`), HTML rendering, `SaveMailbox` with a failing connection, the access matrix and route authorisation. IMAP and SMTP are faked (`J87NL\Mailbox\Testing\FakeConnector` on top of imapengine's own fakes); `UID EXPUNGE` is checked at protocol level with imapengine's `FakeStream`.
- [ ] Run against Dovecot/GreenMail in Docker as an integration test (not done: Docker is not usable without sudo on the dev machine).
- [x] `cid:` URLs survive the sanitizer (they are rewritten before sanitising).
- [ ] Verify the HTML rendering on real newsletters; tune `allowStaticElements()` and style handling.
- [ ] `mailbox:install` command (publish config and migration, add the `@mailbox` Vite alias, publish pages).
- [ ] Move the hardcoded Dutch UI strings in the Vue pages to translations (the table builder's `useTranslations` pattern).
- [ ] Forward with original attachments; reply quoting of HTML instead of plain text.
- [x] Use `UID EXPUNGE` (UIDPLUS) instead of a full expunge when purging from Trash.
- [x] Guard against a manager revoking or lowering the last `manage` row of a mailbox.
- [ ] OAuth onboarding flow (token storage + `ProvidesAccessToken` implementation for Google and Microsoft); the form already accepts `oauth`.
- [ ] "In progress by …" claims and assignment — the step from shared webmail to shared inbox.
- [ ] IMAP IDLE worker as an optional long-running command.
- [ ] Switch the access form to a server-side searchable select once the form builder supports it (HANDOFF item 11).
- [ ] Type-check the Vue pages (`vue-tsc`) in a host app; the packages have no JS build setup.

## Timeouts and time zones

IMAP and SMTP connection attempts give up after `mailbox.connection.timeout` seconds (default 10). Dates are stored in UTC; dates the server formats for display (message list, reply quote) use `mailbox.display_timezone`, falling back to `app.timezone`. ISO strings sent to the frontend stay ISO.

## Testing

Each package runs standalone: `composer install`, then `composer test`, `composer analyse` and `composer format`. Tests never reach a mail server: `FakeConnector::install()` binds a fake `MailboxConnector` whose remote folders record every `UID MOVE`, `UID STORE` and destroy, and whose SMTP transport records sent messages. Host apps can use the same fakes in their own tests.

## Host app setup (manual until the installer exists)

```bash
composer require j87nl/laravel-vue-mailbox
php artisan vendor:publish --tag=mailbox-migrations
php artisan migrate
php artisan vendor:publish --tag=vue-mailbox-pages
```

`vite.config.ts` aliases: `@form-builder`, `@table-builder` (already present with the CrudBuilder) and

```ts
'@mailbox': path.resolve(__dirname, 'vendor/j87nl/laravel-vue-mailbox/resources/js'),
```

Bootstrap an admin and the access mode in `.env`:

```dotenv
MAILBOX_ADMINS=johan@example.com
MAILBOX_ACCESS_MODE=restricted
```

Schedule the sync:

```php
Schedule::command('mailbox:sync')->everyTwoMinutes()->withoutOverlapping();
```

Menu links: `route('mailbox.index')` for the mailbox (gate the link with `accessAnyMailbox`), `route('mailbox.settings.index')` for management.

## Compose editor

The mailbox does **not** follow the FormBuilder's `default-editor`. It sets its own editor in `mailbox.compose.editor` (default `hugerte`, env `MAILBOX_COMPOSE_EDITOR`) with options from `mailbox.compose.editor_options.<editor>`.

Why HugeRTE and not Quill: TinyMCE-family editors write alignment and similar formatting as inline `style` attributes, which mail clients keep, while Quill writes CSS classes (`ql-align-*`, `ql-indent-*`) that mail clients drop. TinyMCE is also the editor webmail clients such as Roundcube have relied on for years. The toolbar is limited to bold, italic, underline, lists, blockquote, links and remove-formatting; pasted images are refused.

Host app requirements, because the HugeRTE adapter deliberately imports no plugins:

```bash
npm install hugerte @hugerte/hugerte-vue
```

```ts
import HugeRteEditor from '@form-builder/wysiwyg/HugeRteEditor.vue'
import { registerWysiwygEditor } from '@form-builder/wysiwyg/registry'
import 'hugerte/plugins/autolink'
import 'hugerte/plugins/link'
import 'hugerte/plugins/lists'

registerWysiwygEditor('hugerte', HugeRteEditor)
```

Switching to another registered adapter later is a config change (`MAILBOX_COMPOSE_EDITOR` plus its options). The compose editor is independent of the host app's FormBuilder `default-editor`.
