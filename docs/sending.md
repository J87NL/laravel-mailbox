# Sending mail

`J87NL\Mailbox\Actions\SendMessage` sends a message through the SMTP server of a mailbox.

```php
use J87NL\Mailbox\Actions\SendMessage;

$messageId = app(SendMessage::class)->handle($mailbox, [
    'to' => ['customer@example.org'],
    'cc' => ['colleague@example.org'],
    'bcc' => [],
    'subject' => 'Your order',
    'body' => '<p>Thanks for your order.</p>',
], $request->user());
```

`handle(Mailbox $mailbox, array $data, ?Authenticatable $user, ?MailboxMessage $replyTo = null): string`

It returns the new `Message-ID`, without angle brackets.

## Data keys

| Key                     | Type                                   | Notes                                                        |
|-------------------------|----------------------------------------|--------------------------------------------------------------|
| `to`                    | `array<string>` or `string`            | Required. A string is split on `,` and `;`                   |
| `cc`, `bcc`             | `array<string>` or `string`            | Optional                                                     |
| `subject`               | `string`                               | Required                                                     |
| `body`                  | `string`                               | Required. HTML; the text part is derived from it             |
| `attachments`           | `array<UploadedFile>`                  | Optional. Other values are ignored                           |
| `forwarded_attachments` | `array<DirectoryTree\ImapEngine\Attachment>` | Optional. Attachments of the original, see below       |
| `quote_source`          | `MailboxMessage`                       | Optional. The quoted message, see below                      |

The [ComposeForm](forms.md#composeform) validates `to`, `cc`, `bcc`, `subject`, `body` and `attachments`.

## What happens

1. The message is built: `From` is the mailbox `email` with its `from_name`, the HTML body plus a plain text part, the
   attachments, and its own `Message-ID` on the domain of the mailbox address.
2. It is sent over SMTP. When SMTP refuses it, the exception is thrown and nothing else happens.
3. Unless `append_sent` is off, a copy is appended to the Sent folder (role `Sent`, or a folder at `Sent` that is
   created when needed), flagged `\Seen`, and a sync of that folder is queued.
4. On a reply, the original is flagged `\Answered`.
5. A `sent` audit entry is written with `in_reply_to` and `to` in its meta.
6. The `MessageSent` event fires.

Once SMTP accepted the message, a failure in step 3 or 4 is reported with `report()` but the send still counts as done,
so nobody sends it twice.

Turn `append_sent` off for Gmail and Microsoft 365: they store sent mail themselves.

## Replies

Pass the original message as `$replyTo`:

```php
$messageId = app(SendMessage::class)->handle($mailbox, $data, $request->user(), $original);
```

When the original has a `Message-ID`, the message gets `In-Reply-To: <original-id>` and a `References` header with the
original `References` followed by the original id. Recipients are not filled in for you; the cached message has
`from_email`, `reply_to`, `to` and `cc` to build them from.

## Forwarding attachments

Fetch the attachments of the original with `FetchMessageBody` and pass them as `forwarded_attachments`:

```php
use J87NL\Mailbox\Actions\FetchMessageBody;

$data['forwarded_attachments'] = app(FetchMessageBody::class)->attachmentsAt($original, [0, 2]);
```

Each is attached with its file name (or `attachment`) and content type. `null` entries are skipped.

## Quoting and inline images

`MessageHtml::quote()` prepares the original for a reply or forward:

```php
use J87NL\Mailbox\Html\MessageHtml;

$quote = app(MessageHtml::class)->quote(
    $original->body_html,
    $original->body_text,
    fn (string $contentId): string => $inlineUrl($original, $contentId),
);
```

- Without an HTML part the text part is quoted, escaped, with line breaks.
- The HTML goes through the reading-pane sanitizer (body content only). Style declarations that load resources
  (`url(...)` and similar) are removed. Remote `https:` and `data:` images are kept; `srcset` is dropped.
- `cid:` images get the URL your closure returns plus a `data-mailbox-cid` attribute, so the editor can show them.

When sending, put the quoted message in `quote_source`. `QuotedImages` then goes over every `<img>` in the body:

- An image marked with `data-mailbox-cid`, or whose `src` is a signed inline URL of that same message (resolved through
  `ResolvesInlineImageUrls`), is fetched from the quoted message (from the raw cache, or over IMAP) and embedded with a fresh Content-ID.
- An image that points at another message, at an unknown part, or at a `cid:` the client made up, is removed.
- Other images, such as `https:` images, are left alone.
- Any remaining occurrence of the inline URL prefix (`ResolvesInlineImageUrls::urlPrefix()`) is removed from attributes
  and text, so an inline route URL never leaves the app.

Without a `ResolvesInlineImageUrls` binding only marked images are embedded. See
[Extension points](extension-points.md#resolvesinlineimageurls).

## Signature

A mailbox can have an HTML `signature`. `SaveMailbox` sanitizes it with `MessageHtml::sanitizeSignature()`:

- the message sanitizer, with `https:` and `data:` images allowed (for logos);
- style declarations that load resources are removed;
- a signature without text or images is stored as `null`.

The package does not insert the signature into the body. The UI package puts it below an empty first paragraph and
above the quote, wrapped in `<div class="mailbox-signature">`. See its
[compose page](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/pages/compose.md#body-and-signature).

## SMTP connection

The transport is a Symfony `EsmtpTransport` built per mailbox, with the timeout from `mailbox.connection.timeout`. TLS
is required unless `smtp_tls` is `false`, see [Security](security.md#smtp-requires-tls). OAuth mailboxes authenticate
with XOAUTH2 and a token from the [token provider](extension-points.md#providesaccesstoken).
