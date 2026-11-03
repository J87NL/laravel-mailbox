# Message templates

A mailbox can have message templates: a name, an optional subject and an HTML body written in the compose editor. A UI
offers them in compose, reply and forward and inserts the chosen one, with its placeholders filled in at that moment.

Table `mailbox_templates`, model `J87NL\Mailbox\Models\MailboxTemplate`, relation `$mailbox->templates()`. The rows are
deleted with their mailbox.

## Saving

```php
use J87NL\Mailbox\Actions\SaveTemplate;
use J87NL\Mailbox\Forms\TemplateForm;

$data = (new TemplateForm(''))->validate($request);

app(SaveTemplate::class)->handle($mailbox, $template, $data); // $template null creates one
```

`TemplateForm` has `name` (required, at most 100 characters), `subject` (optional) and `body` (required, the
[compose editor](forms.md#emaileditor)). The help text of the body lists the placeholders. `SaveTemplate` sanitizes the
body like a [signature](sending.md#signature) (`https:` and `data:` images allowed) and stores an empty subject as
`null`.

## Placeholders

```php
use J87NL\Mailbox\Actions\RenderTemplate;

$rendered = app(RenderTemplate::class)->handle(
    $template,
    $request->user(),
    recipientEmail: 'piet@example.org',
    recipientName: null,
    subject: 'Offerte',
);

$rendered['subject']; // ?string, null when the template has no subject
$rendered['body'];    // HTML
```

| Placeholder | English alias | Value |
|---|---|---|
| `{voornaam}` | `{first_name}` | First word of the recipient's name |
| `{naam}` | `{name}` | Name of the recipient |
| `{onderwerp}` | `{subject}` | The subject passed in |
| `{datum}` | `{date}` | Today, `d-m-Y` in the [display timezone](configuration.md#display-timezone) |
| `{mijn_naam}` | `{my_name}` | The user's `access.user_label` attribute (`name`) |
| `{mailbox_naam}` | `{mailbox_name}` | Name of the mailbox |

Without `recipientName`, the name comes from the mailbox's [address book](contacts.md) entry for `recipientEmail`.
Values are HTML-escaped in the body and inserted as they are in the subject. A placeholder without a value, such as
`{voornaam}` when the recipient's name is unknown, and an unknown placeholder are left as they are, so the user sees what
is still to fill in. Put placeholders in the text, not in a link or other attribute: the sanitizer may drop such an
attribute.

## Access

The actions do not check access. The UI package lets users with `manageMailbox` manage the templates and users with
`workInMailbox` insert them.
