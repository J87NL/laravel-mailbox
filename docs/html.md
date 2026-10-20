# HTML rendering

Mail is untrusted HTML. `J87NL\Mailbox\Html\MessageHtml` turns it into HTML that is safe to show, using
`symfony/html-sanitizer`.

## Rendering a message

```php
use Illuminate\Support\Facades\URL;
use J87NL\Mailbox\Html\MessageHtml;

$html = app(MessageHtml::class)->render(
    $message->body_html,
    $message->body_text,
    fn (string $contentId): string => URL::temporarySignedRoute('mail.inline', now()->addMinutes(30), [
        'message' => $message->id,
        'cid' => $contentId,
    ]),
    allowRemoteImages: $request->boolean('images'),
);
```

`render(?string $html, ?string $text, Closure $cidUrl, bool $allowRemoteImages = false): string`

- Without an HTML part the escaped text part is returned in a `<pre style="white-space: pre-wrap; font: inherit">`.
- `cid:` references are rewritten to the URL your closure returns, before sanitizing (the sanitizer drops `cid:`
  itself). Serving the inline part behind that URL is up to your app; the route in the example is your own.
- Scripts, event handlers, `javascript:` links, iframes and other unsafe elements are removed. Static elements and
  `style` attributes are kept.
- Links get `target="_blank"` and `rel="noopener noreferrer nofollow"`. Allowed link schemes are `http`, `https`,
  `mailto` and `tel`.

## Remote images

Remote images are blocked unless `$allowRemoteImages` is `true` or `mailbox.html.block_remote_images` is `false`.
While they are blocked:

- images may only use `data:`, or the host of your inline image URLs;
- `srcset` is dropped;
- style declarations that can load resources are removed: `url(`, `image(`, `image-set(`, `cross-fade(`, `element(`,
  CSS escapes and `@import`. So `background: url(https://tracker)` does not leak.

Check whether to offer a "show images" button:

```php
$messageHtml = app(MessageHtml::class);

$messageHtml->hasRemoteImages($message->body_html); // remote src, background, srcset or url(...) found
$messageHtml->remoteImagesAllowed();                // true when blocking is switched off in config
$messageHtml->remoteImagesAllowed(true);            // true
```

## Showing the result

The package returns a string; it does not render it. Show the HTML in a sandboxed iframe, not inline in your page. See
[Security](security.md).

The UI package does this for you with an iframe with `srcdoc`, a CSP meta tag and `sandbox` without `allow-scripts`, and
serves the inline images through a signed route. See its
[message frame](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/pages/messages.md#the-message-frame) and
[inline images](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/inline-images.md).

## Quotes and signatures

`MessageHtml` also has `quote()` for reply and forward quotes, and `sanitizeSignature()` for mailbox signatures. See
[Sending mail](sending.md#quoting-and-inline-images).

The constant `MessageHtml::CID_ATTRIBUTE` holds the marker attribute for quoted inline images, `data-mailbox-cid`.
