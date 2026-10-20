# Security

A shared mailbox holds credentials and shows untrusted HTML to many users. This page lists what the package does and
what your app has to do.

## What your app must do

- **Authorize every request.** The actions do not check access. Call `Gate::authorize()` with `accessMailbox`,
  `workInMailbox` or `manageMailbox` before reading or changing a mailbox, and `manageMailboxes` before creating or
  deleting one. See [Access control](access.md).
- **Scope ids to the mailbox.** Load folders and messages through the authorized mailbox, for example
  `$mailbox->messages()->whereKey($ids)->get()`, so a request cannot reach rows of another mailbox. This includes the
  `parent_id` of the [FolderForm](forms.md#folderform).
- **Isolate rendered mail.** Show the output of `MessageHtml::render()` in a sandboxed iframe without `allow-scripts`,
  ideally with a CSP that matches the remote-image choice. See [HTML rendering](html.md).
- **Sign inline image URLs** and keep them short-lived. Only return a result from
  `ResolvesInlineImageUrls::parse()` for a URL with a valid signature.
- **Serve attachments as downloads.** Use `application/octet-stream` and `X-Content-Type-Options: nosniff`, and only
  serve common image types inline.

The UI package does all of this for its own routes. See its
[security](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/security.md) page. Routes of your own still
need these checks.

## Credentials

The IMAP and SMTP passwords use Laravel's `encrypted` cast and are hidden when a `Mailbox` is turned into an array or
JSON. Rotating `APP_KEY` makes them unreadable, so they must be entered again.

## SMTP requires TLS

SMTP always requires TLS: implicit TLS on port 465, mandatory STARTTLS on other ports. A man-in-the-middle can therefore
not strip STARTTLS and read the password.

This holds for the default `smtp_tls = null`. `smtp_tls = true` uses implicit TLS on any port. The only opt-out is
`smtp_tls = false` on the mailbox row, for a local relay without TLS. It is not in the
[MailboxForm](forms.md#mailboxform) or `mailbox:add` on purpose; set it in the database or from code.

## Saving a mailbox

`SaveMailbox` protects stored credentials:

- On edit, an empty password field keeps the stored password.
- For mailboxes that do not use OAuth, the password must be entered again when a connection field of that protocol
  changes: `imap_host`, `imap_port`, `imap_encryption` or `imap_username` for IMAP, and `smtp_host`, `smtp_port`,
  `smtp_tls` or `smtp_username` for SMTP. A manager can therefore not send the stored password to a server of
  their choice.
- For OAuth mailboxes only users with `manageMailboxes` may change any of these connection fields, because the
  connection test would send the access token there. A call without a user (such as the CLI) is trusted.
- The audit entry lists the changed fields, never the passwords.

## HTML mail

- Mail HTML is sanitized with `symfony/html-sanitizer`. Scripts, event handlers, `javascript:` links and frames are
  removed.
- Remote images are blocked until the user allows them. While blocked, resource-loading styles (`url(`, `image-set(`,
  CSS escapes, `@import` and similar) and `srcset` are removed as well, so tracking pixels in styles do not load.
- Links open in a new tab with `rel="noopener noreferrer nofollow"`.
- Signatures and reply quotes use the same base sanitizer, but without the forced `rel` and `target` on links, and with
  `https:` and `data:` images allowed.

## Quoted inline images

When sending, only images that are marked with `data-mailbox-cid` or point at a signed inline URL of the quoted message
are embedded, and only from that message. Images that point at another message, at an unknown part or at a `cid:` the
client made up are dropped. A client can therefore never pull a part from a message it did not quote. Inline route URLs
are removed from the outgoing body. See [Sending mail](sending.md#quoting-and-inline-images).

## Deleting in a shared mailbox

Purging from Trash expunges only the selected UIDs with `UID EXPUNGE`. Without UIDPLUS nothing is expunged, so mail
another client flagged `\Deleted` is never removed by accident. See [Message actions](message-actions.md#deleting).

## Access data

- The access form and `mailbox:grant` list every user and group the resolver knows. Every manager therefore sees all
  user names.
- The last manage row of a mailbox cannot be removed or lowered.
- Grants and revocations are written to the [audit trail](audit-trail.md).
- Admins via `MAILBOX_ADMINS` need a verified email when the user model implements `MustVerifyEmail`.
