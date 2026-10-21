# Changelog

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
