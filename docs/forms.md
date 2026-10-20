# Forms

The forms in `J87NL\Mailbox\Forms` are TranquilTools FormBuilder forms (`AbstractForm`). They hold the fields and the
validation rules; a renderer, such as the Vue package, shows them. See the FormBuilder documentation for rendering and
validating a form.

Labels come from the `mailbox::mailbox` translations. This package registers no routes; the route names in the
examples stand for routes of your own app.

## MailboxForm

Create and edit a mailbox.

```php
use J87NL\Mailbox\Forms\MailboxForm;

new MailboxForm(route('mailboxes.store'));                 // POST
new MailboxForm(route('mailboxes.update', $mailbox), $mailbox); // PUT, filled with the mailbox
```

Sections and fields:

- **General**: `name`, `email`, `from_name`, `authentication` (`plain` or `oauth`), `active`, `append_sent`,
  `signature` (the compose editor).
- **IMAP**: `imap_host`, `imap_port` (993), `imap_encryption` (`ssl` or `starttls`), `imap_username`, `imap_password`.
- **SMTP**: `smtp_host`, `smtp_port` (587), `smtp_username`, `smtp_password`.

When creating, the passwords are required unless `authentication` is `oauth`. When editing they are optional, and
empty keeps the stored one. Pass the validated data to [SaveMailbox](message-actions.md#saving-a-mailbox).

`smtp_tls` is not in the form on purpose, see [Security](security.md#smtp-requires-tls).

## AccessForm

Grant a user or group access.

```php
use J87NL\Mailbox\Forms\AccessForm;

new AccessForm(route('mailboxes.access.store', $mailbox));
```

Fields: `accessor` (a searchable select of everything `ResolvesAccessors::assignable()` returns, by key) and `level`
(`read`, `write` or `manage`, default `read`). Split the `accessor` key on the first `:` into type and id, and pass them
to [GrantAccess](access.md#granting-and-revoking).

## ComposeForm

Write, reply to or forward a message.

```php
use J87NL\Mailbox\Forms\ComposeForm;

new ComposeForm(
    action: route('mail.send', $mailbox),
    defaults: ['to' => ['customer@example.org'], 'subject' => 'Re: Question', 'body' => $quote],
    suggestionsUrl: route('mail.suggestions', $mailbox),
    forwardedAttachments: [
        ['key' => '0', 'name' => 'invoice.pdf', 'size' => 12345],
    ],
);
```

Fields:

| Field                | Type                    | Rules                                       |
|----------------------|-------------------------|---------------------------------------------|
| `reply_to_message`   | `Hidden`                | nullable, integer                           |
| `forward_message`    | `Hidden`                | nullable, integer                           |
| `to`                 | `Tags`                  | required, email addresses, at most 100     |
| `cc`, `bcc`          | `Tags`                  | email addresses, at most 100               |
| `subject`            | `Text`                  | required, at most 250 characters            |
| `body`               | Wysiwyg (compose editor)| required                                    |
| `attachments`        | `Files`                 | nullable, array                             |

The recipient fields ask `suggestionsUrl` for suggestions; answer it with
[SuggestAddresses](contacts.md#recipient-suggestions).

`forwardedAttachments` are shown as current, removable files of the `attachments` field. After submit,
`keptForwardedAttachments()` returns the keys the user kept:

```php
$keys = $form->keptForwardedAttachments($request);
```

Turn those keys into attachments yourself and pass them as `forwarded_attachments` to
[SendMessage](sending.md#forwarding-attachments).

The hidden `reply_to_message` and `forward_message` fields carry ids for your controller; the package does not read
them.

## FolderForm

Create a folder.

```php
use J87NL\Mailbox\Forms\FolderForm;

new FolderForm($mailbox, route('mail.folders.store', $mailbox));
```

Fields: `name` (required, at most 100 characters, no `/`, `\`, `*` or `%`) and `parent_id` (optional, a select of the
mailbox's folders with decoded paths). Pass them to [CreateFolder](message-actions.md#creating-folders).

The `parent_id` rule is `exists:mailbox_folders,id`, which does not limit the folder to this mailbox. Look the parent up
through `$mailbox->folders()` before using it.

## ContactForm

Create or edit an address book contact.

```php
use J87NL\Mailbox\Forms\ContactForm;

new ContactForm($mailbox, route('mail.contacts.store', $mailbox));                      // POST
new ContactForm($mailbox, route('mail.contacts.update', [$mailbox, $contact]), $contact); // PUT
```

Fields: `name`, `email` (required, RFC address, unique within the mailbox), `organisation` and `notes` (at most 5000
characters).

## EmailEditor

`EmailEditor::make(string $name)` returns a FormBuilder `Wysiwyg` field with the editor from `mailbox.compose.editor`,
the `email` profile and the options from `mailbox.compose.editor_options.<editor>`. The body and signature fields use it.

```php
use J87NL\Mailbox\Forms\EmailEditor;

EmailEditor::make('reply_template')->label('Reply template');
```
