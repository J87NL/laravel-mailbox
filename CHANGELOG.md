# Changelog

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
