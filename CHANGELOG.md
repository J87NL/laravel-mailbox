# Changelog

## 0.10.0 - 2026-10-30

- New `FetchOlderMessages` action and `FetchOlderMessagesJob`: fetch mail from before the sync window on request. Each call stores the `sync.older_batch_size` (new, default `100`) newest messages with a UID below the lowest cached UID of the folder; a folder without cached messages gets its newest batch, below `last_uid` when it was synced before. Messages flagged `\Deleted` are skipped, but the lowest UID of each batch, deleted ones included, is kept in the new `mailbox_folders.oldest_uid`, so a batch of only deleted messages does not come back. When a batch comes back empty or reaches UID 1, the new `mailbox_folders.older_exhausted` is set and later calls return `0` without asking the server. A new `UIDVALIDITY` clears both. `last_uid` does not move and `MessageReceived` never fires; unknown messages in a watched folder get a `received` audit entry as on a first sync. Not selectable and skipped folders are left alone. It takes the same per-folder cache lock as the sync (`SyncFolderMessages::LOCK_KEY`, for `sync.job_timeout_seconds` + 60 seconds), reloads the folder inside it and returns `null` while the folder is being synced; the job then releases itself for 30 seconds, at most three tries. When the server reports a new `UIDVALIDITY` it stores nothing and queues a `SyncFolderJob`. The job is unique per folder for 300 seconds and lists a failure in the mailbox's `last_error` (read timeouts in `handle()`, other errors in `failed()`). See `docs/sync.md#older-messages`.
- New column `mailbox_folders.message_count`: the folder sync stores `MESSAGES` from the folder status next to `unseen_count` (the cached row count when the server does not report it). A folder whose `message_count` is higher than its cached rows and that is not `older_exhausted` has older mail on the server (`MESSAGES` also counts messages flagged `\Deleted`). Before, a folder could show unread mail from before `sync.initial_days` in `unseen_count` while its list stayed empty.
- **Migration:** publish the migrations again and migrate (`php artisan vendor:publish --tag=mailbox-migrations` and `php artisan migrate`) to add `add_message_count_to_mailbox_folders_table` (`message_count`, `oldest_uid`, `older_exhausted`). The column fills on the next completed sync of each folder. See `docs/upgrading.md`.
- New `J87NL\Mailbox\Sync\StoreRemoteMessages`: stores fetched messages for the sync and `FetchOlderMessages`. `SyncFolderMessages` takes it in its constructor instead of `ConversationKeys`.
- Testing: a `RecordingFolder` query with `limit()` returns that many messages in its fetch order, as a real server does. See `docs/testing.md`.

## 0.9.0 - 2026-10-29

- The four MCP tools carry `openWorldHint` (`#[IsOpenWorld]`): they bring in text written by outsiders. A hub or client can use it to keep them away from tools that change things.
- New `MailboxMcp::readTools()`: the three reading tools without `update-mailbox-message-tool`.
- Docs: `docs/mcp.md#keep-mail-away-from-write-tools` explains why the mailbox tools belong on a server or connector without write tools, and what to do when they cannot be separated. `docs/upgrading.md` and the README point there.
- No migration and no config publish needed.

## 0.8.0 - 2026-10-29

- **Behaviour change:** a user with `Manage` on an inactive mailbox, through their own access row or a group, passes `manageMailbox` on it, so they can open its settings and set it active again. Before, only `MAILBOX_ADMINS` reached an inactive mailbox, so in an app without admins a manager who deactivated a mailbox locked everybody out of it. `accessMailbox` and `workInMailbox` on an inactive mailbox still pass for admins only, `Read` and `Write` rows and open mode give nothing there, and `access.user_query` and a group's `query` still apply. An app-defined `manageMailboxes` gives no level on any mailbox. `MailboxAccessResolver::allows()` only answers with `isAdmin()` for `Read` and `Write` on an inactive mailbox. See `docs/access.md#inactive-mailboxes` and `docs/upgrading.md`.
- No migration and no config publish needed.

## 0.7.1 - 2026-10-29

- An IMAP read timeout (`ImapConnectionTimedOutException`) while a mailbox sync fetches the folder list, for example on `LOGIN`, no longer fails `SyncMailboxJob` or reaches `report()`. It is listed in `last_error`, `synced_at` stays as it was, and the next scheduled run tries again. Other errors at that point are still stored and thrown. A `SyncFolderJob` that runs into a read timeout also ends without failing and lists the folder with the error in the mailbox's `last_error`. Before, a briefly slow IMAP server still filled the error tracker through this path, which 0.5.5 left open. See `docs/sync.md#what-a-mailbox-sync-does`.
- The MCP tools carry annotations: `list-mailboxes-tool`, `list-mailbox-messages-tool` and `get-mailbox-message-tool` are read-only (`#[IsReadOnly]`), `update-mailbox-message-tool` is not, and none is destructive. A hub or client that offers read-only tools to some users can now offer the reading tools to them. See `docs/mcp.md#the-tools`.
- Docs: `docs/mcp.md` and the `suggest` entry say `laravel/mcp` `^0.5` or newer; host apps run the tools on 0.9 and 1.x.
- No migration and no config publish needed.

## 0.7.0 - 2026-10-28

- **Behaviour change:** `access.user_query` now applies to everyone. A user it filters out is no admin even with their email in `MAILBOX_ADMINS`, gets no level in open mode, and fails `accessAnyMailbox`, `manageMailboxes`, `accessMailbox`, `workInMailbox` and `manageMailbox`, also on an inactive mailbox. This also holds with a custom `ResolvesAccessors`. A host app no longer needs its own `Gate::before` for this, unless it defines one of these abilities itself (for example its own `manageMailboxes`), which then has to apply the filter itself. `MailboxAccessResolver` runs the query at most once per user per request (new `MailboxAccessResolver::admits()`, cleared by `flush()`) and `RelationAccessorResolver::accessorsFor()` reuses that answer, so checking several mailboxes without a `preload()` no longer runs the query once per mailbox. A user that is not a `mailbox.user_model` is not filtered. See `docs/access.md#keeping-users-and-groups-out` and `docs/upgrading.md`.
- A group's `prefix` in `access.groups` may be a translation key: it is translated when a translation exists for the current locale (as with `__()`, a JSON translation counts too) and shown as it is otherwise, so the config stays cacheable and plain strings keep working. See `docs/access.md#users-and-groups`.
- New `J87NL\Mailbox\Access\AccessQuery` with `for($model, $query)` and `admitsUser($user)`, shared by `RelationAccessorResolver` and `MailboxAccessResolver`.
- Docs: the menu gate is now the same on every page: `@canany(['accessAnyMailbox', 'manageAnyMailbox'])` with the UI package, which defines `manageAnyMailbox`, and `accessAnyMailbox` or `manageMailboxes` without it (`README.md`, `docs/access.md`, `docs/installation.md`). `docs/mcp.md` has a section for apps that read `$tools` by reflection, wrap the tools for an in-app assistant or resolve the user inside the tool: subclass the package tools, keep the names and override `mailboxUser()`.
- No migration and no config publish needed.

## 0.6.0 - 2026-10-28

- New `access.user_query` and a `query` per entry of `access.groups`: an invokable class name or a callable that receives the Eloquent `Builder` of the user or group model and narrows it, for example to keep a `client` role out. `RelationAccessorResolver` applies it in `search()`, `assignable()`, `labels()` (so `ExistingAccessor` rejects a filtered accessor) and `accessorsFor()`: a filtered user gets no level at all, and a filtered group grants nothing. Admins and open mode are not affected. See `docs/access.md#keeping-users-and-groups-out`.
- Model factories for `Mailbox` (`inactive()`, `withInbox()`), `MailboxFolder` (`role()`) and `MailboxMessage` (`seen()`) in `J87NL\Mailbox\Database\Factories`, for tests in the host app. See `docs/testing.md#factories`.
- The published `config/mailbox.php` lists `access.user_search` (`name` and `email`) and `access.user_query` (`null`), and has a blank line after `declare(strict_types=1);`.
- Docs: the schedule example in `docs/installation.md` also shows `app/Console/Kernel.php`, and `docs/access.md` says to gate the menu on `accessAnyMailbox` or `manageMailboxes` when the app defines `manageMailboxes` itself.
- MCP tools for a `laravel/mcp` server in `J87NL\Mailbox\Mcp\Tools`: `list-mailboxes-tool`, `list-mailbox-messages-tool`, `get-mailbox-message-tool` (body as plain text, at most 20000 characters, not marked read) and `update-mailbox-message-tool` (`read`, `unread`, `flag`, `unflag`, `archive` through `MarkMessages` and `ArchiveMessages`, with the user in the audit trail). They run for `$request->user()`, need `accessAnyMailbox`, then `accessMailbox` to read and `workInMailbox` to change. A mailbox without access gives the same error as a missing one, and an IMAP failure gives a generic error. They never send, reply, forward or delete mail, and their descriptions mark mail content as data, not instructions. `MailboxMcp::tools()` returns the classes for a server's `$tools`; the service provider registers nothing. `laravel/mcp` is suggested, not required. See `docs/mcp.md`.
- No migration and no config publish needed. A published config without the new keys uses the defaults.

## 0.5.7 - 2026-10-28

- `docs/sync.md#time-budget` no longer asks for a job timeout above one and a half times the budget. That rule predates the read deadline of 0.5.4 and the defaults (40 and 55) did not meet it. The page now says how far past the budget a slow step can run before its reads stop.
- No migration and no config publish needed.

## 0.5.6 - 2026-10-28

- The historical v0.1 design notes (`docs/ARCHITECTURE.md`) and the TranquilTools handoff list (`docs/HANDOFF-tranquil-tools.md`) are removed from the docs. The current behaviour is described in the other docs pages. The README and the docs sidebar no longer link to them.
- No migration and no config publish needed.

## 0.5.5 - 2026-10-27

- A folder sync that fails on an IMAP read timeout (`ImapConnectionTimedOutException`) is no longer passed to `report()`. It is still listed in `last_error` and stops the run, and the next scheduled run tries again. A server that is briefly slow no longer fills the error tracker. Other connection, parser and stream errors are still reported. A timeout while the folder list is fetched (for example on `LOGIN`) is still thrown and fails the job. See `docs/sync.md#what-a-mailbox-sync-does`.
- No migration and no config publish needed.

## 0.5.4 - 2026-10-27

- A budgeted sync job stops reading from IMAP `SyncBudget::JOB_TIMEOUT_MARGIN_SECONDS` (5) seconds before `sync.job_timeout_seconds`. Each read waits at most `connection.read_timeout` or the time left until that moment, whichever is shorter. A server that stalls late in a run now fails the command with `ImapConnectionTimedOutException`, which the sync handles and lists in `last_error`, instead of the worker killing the job with `TimeoutExceededException`. Before, a slow step that started with just over its estimate left could run past the job timeout, because `read_timeout` only bounds each single read. See `docs/sync.md#time-budget`.
- New `SyncBudget::seconds($seconds, $jobTimeoutSeconds)` and `SyncBudget::readDeadline()`; `SyncBudget::fromConfig()` passes the job timeout. New `MailboxConnector::readUntil($mailbox, $deadline)`, `ImapMailbox::readUntil()` and `TimeoutImapStream::readUntil()`.
- Testing: `FakeConnector::$readDeadlines` holds the read deadline per mailbox id.
- No migration and no config publish needed.

## 0.5.3 - 2026-10-26

- Every step of a folder sync is now under the time budget: the folder status, the search for new messages with its first chunk, each further chunk and the flag refresh. Before a step the sync checks whether it still fits, judged by how long that step took the last time in that folder (kept in the cache for a day), with at least `sync.step_reserve_seconds` (new config key, `MAILBOX_SYNC_STEP_RESERVE`, default `5`). A measured duration counts for at most half the budget, so one outlier does not block a step for good. A step that does not fit is deferred to the next run, so a run only goes over its budget when a step takes longer than its estimate. See `docs/sync.md#time-budget`.
- The flag refresh works in pieces of `sync.flag_chunk_size` messages (new config key, default `100`) from the newest message down, and resumes where the budget stopped it. New messages are fetched completely before the refresh starts, and the folder's `synced_at` is set even when the refresh is deferred.
- IMAP reads give up after `connection.read_timeout` seconds without data (new config key, `MAILBOX_CONNECTION_READ_TIMEOUT`, default `15`), so a hanging server fails the command instead of running into the job timeout. A mailbox sync that loses its connection this way, or gets a response it cannot parse, skips the remaining folders and lists the error in `last_error`.
- A sync job that deferred something logs one `info` line, `Mailbox sync deferred work to the next run`, with the mailbox (and folder) id and per folder the deferred step (`status`, `fetch`, `refresh` or `not reached`), without message data.
- **Behaviour change:** folders with a special-use attribute in `sync.skip_special_use` (new config key, default `\All`, `\Important` and `\Flagged`: Gmail's All Mail, Important and Starred) are no longer synced. They only repeat mail from the inbox and labels, and the first sync of All Mail searched the whole archive. They stay in the folder list with their role and stay selectable; messages cached before are kept but no longer refreshed, and their unseen count is set to 0. New `MailboxFolder::skipsSync()`; `needsSync()` returns `false` for such a folder. Set the key to `[]` to sync them again. There is no per-mailbox setting. See `docs/sync.md#skipped-folders` and `docs/upgrading.md`.
- Testing: `RecordingFolder` has `$secondsPerStatus`, `$secondsPerSearch`, `$secondsPerFetch` and `$fetches`.
- No migration and no config publish needed. A published config without the new keys uses the defaults.

## 0.5.2 - 2026-10-26

- The sync time budget works in hosts that use immutable dates (`Date::use(CarbonImmutable::class)`). 0.5.1 threw a `TypeError` in `SyncBudget` there, so every budgeted sync failed.

## 0.5.1 - 2026-10-26

- The sync jobs work within a time budget: `SyncMailboxJob` and `SyncFolderJob` stop after `sync.time_budget_seconds` seconds (new config key, `MAILBOX_SYNC_TIME_BUDGET`, default `40`) and leave the rest to the next scheduled run, instead of being killed by the worker timeout on a large mailbox. The budget is checked before every folder and after every stored chunk; a stopped folder keeps its progress (`last_uid` is saved per chunk) and continues there next run. No extra jobs are dispatched. See `docs/sync.md#time-budget`.
- Folders are synced inbox first, then folders that finished a sync before (longest ago first), then folders that are still backfilling, so a big first sync no longer keeps new mail in the inbox and other folders waiting.
- Both sync jobs have `$timeout = 55` (new config key `sync.job_timeout_seconds`, `MAILBOX_SYNC_JOB_TIMEOUT`) and `$failOnTimeout = true`. Keep budget < job timeout < the queue's `retry_after` (default `90`) when changing them.
- The per-folder sync lock of a budgeted sync expires after the job timeout plus 60 seconds instead of 600 seconds, so a killed job no longer blocks that folder for ten minutes.
- A `UIDVALIDITY` reset clears the folder's `synced_at` until it is fetched completely again, so a reset that takes several runs does not announce the refetched mail as received.
- `SyncMailbox::handle()` and `SyncFolderMessages::handle()` take an optional `J87NL\Mailbox\Sync\SyncBudget`; without one (also `mailbox:sync --now`) they sync everything in one go, as before.
- Testing: `RecordingFolder::$chunkFetches` and `RecordingFolder::$secondsPerChunk`, and the fake message query honours UID ranges.
- No migration and no config publish needed. A published config without the new keys uses the defaults.

## 0.5.0 - 2026-10-24

- The access form no longer sends every user and group to the browser. `AccessForm` takes a second argument, `searchUrl`, and its `accessor` select searches on the server from two characters on (`AccessForm::MIN_SEARCH_LENGTH`). The endpoint answers `GET {searchUrl}?q=<term>` with `[{value, label}]`; `j87nl/laravel-vue-mailbox` 0.7.0 ships it. See `docs/forms.md#accessform`.
- New `ResolvesAccessors::search(string $term, int $limit)`. `RelationAccessorResolver` matches users on `access.user_search` (new optional config key, default `user_label` and `email`) and groups on their optional `search` columns (default `label`), case-insensitively, with `%` and `_` taken literally.
- New validation rule `J87NL\Mailbox\Rules\ExistingAccessor`: the `type:id` key must name an existing model of a configured accessor source. `AccessForm` uses it instead of an `in:` rule over all accessors.
- Requires `tranquil-tools/laravel-form-builder` ^1.8.
- **Breaking for custom code:** pass a search url to `new AccessForm($action, $searchUrl)` (empty strings to validate only), and add `search()` to a custom `ResolvesAccessors`. Hosts on `j87nl/laravel-vue-mailbox` only bump both packages.

## 0.4.0 - 2026-10-24

- **Needs a migration:** the new migration `add_conversations_to_mailbox_tables` adds `mailboxes.conversation_view` (boolean, default `false`) and `mailbox_messages.thread_key` with indexes on `(mailbox_folder_id, thread_key)` and `(mailbox_id, thread_key)`. The sync writes `thread_key`, so publish and run it before deploying this version (release it as 0.4.0). Then run `php artisan mailbox:threads` once.
- Conversations: `J87NL\Mailbox\Threading\ConversationKeys` gives each message a conversation key from `References`/`In-Reply-To` (following messages already known in the mailbox), falling back to the normalized subject for a reply without those headers, and to the message's own `Message-ID`. The folder sync keys new messages; the new command `mailbox:threads {mailbox?} {--fresh}` keys stored ones. A reply without reply headers joins the newest message with the same normalized subject from the `threading.subject_days` days before the reply's own date (new config key, default 90). When a message is keyed, replies that arrived earlier and were keyed with its id move to its conversation. See `docs/sync.md#conversations`.
- `MailboxForm` has a `conversation_view` toggle ("Group messages into conversations"), off by default; `Mailbox` casts it to bool.
- The plain-text part of a sent message keeps link targets (`label (url)`), separates table cells, collapses whitespace per line and leaves out `<style>`, `<script>` and `<head>` content, so HTML signatures stay readable. A pattern that fails (such as the PCRE backtrack limit on a large unclosed tag) keeps the text of the previous step instead of sending an empty text part.
- `QuotedImages` no longer reports a `MessageGoneException` when the quoted message left the server; it logs an info line and sends without the quoted images.

## 0.3.4 - 2026-10-23

- `FetchMessageBody` keeps the raw message of its first fetch on a private disk (`mailbox/raw/{mailbox id}/{message id}.eml`) for `cache.raw_hours` hours (`MAILBOX_RAW_CACHE_HOURS`, default `24`) on `cache.raw_disk` (`MAILBOX_RAW_CACHE_DISK`, default `local`). Inline images, attachment downloads, forwarded attachments and quoted images are served from it instead of fetching the whole message from the server again for every image. Concurrent requests for the same message wait for one fetch under a cache lock. `null` or `0` switches it off. A published config without these keys uses the defaults.
- Raw messages are written with private visibility through a temporary file, so a concurrent reader never sees half a message.
- Moving, deleting and forgetting a message, a sync that finds messages gone from the server or a changed UIDVALIDITY, and deleting a mailbox remove the raw messages. `mailbox:prune-bodies` removes expired raw messages and those of the bodies it prunes, also when body pruning is switched off.
- New `MarkMessages::readInBackground()`: updates the rows, the `unseen_count` and the audit trail right away and sets the `\Seen` flag on the server after the response through the new `FlagRemoteMessagesJob`, without a queue worker. A failure of that IMAP call is reported instead of thrown.
- `RecordingFolder::$messageFetches` counts single-message fetches in tests.

## 0.3.3 - 2026-10-21

- `MailboxMessage::links()` only returns the links of the message's own mailbox, also when eager loaded, counted or used in `has()`/`whereHas()`, and `$message->links()->create()` fills the `mailbox_id`. Until now a copy of the same `Message-ID` in another mailbox shared its links. The method still declares `HasMany`; it returns the new `J87NL\Mailbox\Relations\MessageLinks`.
- `FolderForm` only accepts a `parent_id` of the form's mailbox.
- New action `LinkMessage` links a message to a model and writes the `linked` audit entry, which was never written until now. It refuses an unsaved model and writes the link and the entry in one transaction. `RecordAudit::forMessages()` takes an optional `meta`.
- A `sent` audit entry stores `to` as a list of bare addresses, also when the recipients were given as one string.
- Removed the autoload entry for the non-existent `database/factories` folder.
- The README no longer says dates are stored in UTC: like Laravel's own timestamps they are stored in `app.timezone`. `docs/ARCHITECTURE.md` is linked as the historical v0.1 design notes.

## 0.3.2 - 2026-10-19

- Requires `directorytree/imapengine` ^1.22.3 (command injection via the ID command), `symfony/html-sanitizer` ^7.4.13 or ^8.0.13 (URL attribute sanitising fixes) and `symfony/mailer` ^7.4.12 or ^8.0.12 (argument injection in `SendmailTransport`), so a host can no longer resolve the vulnerable versions.

## 0.3.1 - 2026-10-14

- `MarkMessages::read()` and `unread()` keep the folder's `unseen_count` in step: it changes by the number of messages whose seen state actually changed and never drops below zero. Until now it only changed on the next sync.

## 0.3.0 - 2026-10-14

- `MessageHtml::quote()` sanitises an original message for quoting: body content only, inline styles without resource-loading declarations, remote images kept, `cid:` images rewritten to the given URL and marked with `data-mailbox-cid`. Without an HTML part the text part is quoted.
- `SendMessage` embeds quoted inline images (`QuotedImages`): an `<img>` marked with `data-mailbox-cid`, or pointing at a signed inline URL of the quoted message (`quote_source` in the data), is fetched from that message over IMAP and embedded with a fresh Content-ID. Images of other messages, unknown parts and client-made `cid:` references are dropped.
- New contract `ResolvesInlineImageUrls` (bound to a no-op by default) and `FetchMessageBody::inlineAttachments()`.
- The default HugeRTE options keep `data-mailbox-cid` on images (`extended_valid_elements`). Hosts with a published `config/mailbox.php` add that line.
- Translations `quote_forwarded` and `quote_wrote`.
- Quotes only keep `https:` and `data:` images and drop `srcset`. Inline images are found with PHP's HTML5 parser (`Dom\HTMLDocument`, first `src` wins) after sanitising; any `data-mailbox-cid` the original carried is removed.
- The outgoing body is parsed with the same parser: marked or signed inline images are embedded from the quote source or removed, and every remaining occurrence of the inline route URL (`ResolvesInlineImageUrls::urlPrefix()`) is stripped from attributes, text and the text part. `FetchMessageBody::inlineAttachments()` skips parts without a Content-ID.

## 0.2.0 - 2026-10-13

- Requires `tranquil-tools/laravel-form-builder` ^1.7.
- New migrations `add_signature_to_mailboxes_table`, `create_mailbox_contacts_table` and `add_sent_at_index_to_mailbox_messages_table`; publish the migrations again and migrate.
- Address book per mailbox: `MailboxContact`, `ContactForm`, `AddContact`.
- Recipient suggestions (`SuggestAddresses`): address book, host providers (`contacts.providers`, `ProvidesContactSuggestions`), then addresses seen in the last `contacts.scanned_messages` (500) messages of the mailbox, matched case-insensitively in PHP, also for non-ASCII names.
- The `to` value in the meta of a `sent` audit entry is now the list of addresses (was the comma separated string from the form).
- Compose form: To, Cc and Bcc are `Tags` fields with e-mail validation and suggestions; forwarding offers the original attachments; `SendMessage` accepts address lists and `forwarded_attachments`.
- Removed `Rules\EmailList` (replaced by `Tags::emails()`).
- Mailbox signature (sanitised, https logos allowed, resource-loading styles stripped), inserted by the compose defaults.
- Compose and signature editors use the FormBuilder `email` profile; `compose.editor_options` is now an override only and no longer carries toolbars.
- `mailbox:prune-bodies` and `cache.body_days` to forget old cached bodies.
- `ContactForm` rejects non-string input; `FolderForm` shows decoded folder paths.
- UI texts for the Vue pages, folder labels and audit actions in the translations.

## 0.1.3 - 2026-10-13

- Space the fields of the compose and folder forms.

## 0.1.2 - 2026-10-13

- Fetch the folder list on demand (`SyncFolders::bootstrap()`) for a mailbox that has no folders yet.
- Queue a sync of the Sent folder after a sent message is stored there.
- Add `mailbox.connection.timeout` (`MAILBOX_CONNECTION_TIMEOUT`, default 10 s) for IMAP and SMTP.
- Add `mailbox.display_timezone` (`MAILBOX_DISPLAY_TIMEZONE`) and the `DisplayTime` helper.
- Add translated folder role labels (`FolderRole::label()`).
- Split the mailbox form into general, IMAP and SMTP sections.
- Require PHP 8.4 in the docs; add `.gitattributes`.
- Skip the on-demand folder list for inactive mailboxes and try it at most once a minute per mailbox.
- Fall back to `app.timezone` when `mailbox.display_timezone` is not a valid timezone.

## 0.1.1 - 2026-10-13

- Allow Symfony 8 for symfony/mailer and symfony/html-sanitizer.

## 0.1.0 - 2026-10-13

- First release.
