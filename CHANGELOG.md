# Changelog

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
