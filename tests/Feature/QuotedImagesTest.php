<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use J87NL\Mailbox\Actions\FetchMessageBody;
use J87NL\Mailbox\Actions\SendMessage;
use J87NL\Mailbox\Contracts\ResolvesInlineImageUrls;
use J87NL\Mailbox\Html\HtmlFragment;
use J87NL\Mailbox\Html\MessageHtml;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class TestInlineImageUrls implements ResolvesInlineImageUrls
{
    public function parse(string $url): ?array
    {
        if (preg_match('#^https://app\.test/inline/(\d+)/(.+)$#', $url, $match) !== 1) {
            return null;
        }

        return [
            'content_id' => $match[2],
            'message_id' => (int) $match[1],
        ];
    }

    public function urlPrefix(): ?string
    {
        return 'https://app.test/inline/';
    }
}

/**
 * @return array{0: FakeMessage, 1: array<int, string>}
 */
function sourceWithInlineImages(): array
{
    $raw = (new Email)
        ->from('sender@example.org')
        ->to('team@example.com')
        ->subject('Newsletter')
        ->html('<p>Hi</p><img src="cid:logo"><img src="cid:banner">')
        ->embed('LOGO-BYTES', 'logo', 'image/png')
        ->embed('BANNER-BYTES', 'banner', 'image/png')
        ->toString();

    $message = new FakeMessage(1, [], $raw);
    $contentIds = array_map(
        fn (Attachment $attachment): string => trim((string) $attachment->contentId(), '<>'),
        $message->attachments(),
    );

    return [
        $message,
        $contentIds,
    ];
}

beforeEach(function (): void {
    $this->app->instance(ResolvesInlineImageUrls::class, new TestInlineImageUrls);
});

it('embeds the quoted inline images the user kept and drops the rest', function (): void {
    $mailbox = mailbox([
        'append_sent' => false,
    ]);
    $inbox = folder($mailbox);
    $source = storedMessage($inbox, 1)->load('folder');
    $other = storedMessage($inbox, 2);
    [$remote, $contentIds] = sourceWithInlineImages();
    remoteFolder($mailbox, 'INBOX')->setMessages([
        $remote,
    ]);

    $body = implode('', [
        '<p>See below</p>',
        sprintf('<img src="https://app.test/inline/%d/%s" data-mailbox-cid="%s" alt="Logo">', $source->id, $contentIds[0], $contentIds[0]),
        sprintf('<img src="https://app.test/inline/%d/%s">', $other->id, $contentIds[1]),
        '<img src="cid:guessed@example.org">',
        '<img src="https://cdn.example.org/remote.png">',
    ]);

    app(SendMessage::class)->handle($mailbox, [
        'body' => $body,
        'quote_source' => $source,
        'subject' => 'Fwd: Newsletter',
        'to' => [
            'colleague@example.org',
        ],
    ], null);

    /** @var Email $sent */
    $sent = test()->connector->transport->sent[0];
    $inline = array_values(array_filter($sent->getAttachments(), fn (DataPart $part): bool => $part->getDisposition() === 'inline'));
    $html = (string) $sent->getHtmlBody();

    expect($inline)->toHaveCount(1)
        ->and($inline[0]->getBody())->toBe('LOGO-BYTES')
        ->and($html)->toContain(sprintf('src="cid:%s"', $inline[0]->getName()))
        ->and($html)->toContain('alt="Logo"')
        ->and($html)->toContain('https://cdn.example.org/remote.png')
        ->and($html)->not->toContain('data-mailbox-cid')
        ->and($html)->not->toContain('app.test/inline')
        ->and($html)->not->toContain('guessed@example.org')
        ->and($sent->toString())->toContain('multipart/related');
});

it('quotes the sanitised html body and marks inline images for the editor', function (): void {
    $quote = app(MessageHtml::class)->quote(
        implode('', [
            '<html><head><style>p{color:red}</style><title>Title</title></head><body>',
            '<p style="color: blue; background: url(https://tracker.test/a.gif)">Hello <a href="https://example.org">link</a></p>',
            '<img src="cid:logo@example.org" alt="Logo"><img src="https://cdn.example.org/logo.png">',
            '<script>alert(1)</script></body></html>',
        ]),
        null,
        fn (string $contentId): string => sprintf('https://app.test/inline/1/%s', $contentId),
    );

    expect(html_entity_decode($quote))->toContain('<p style="color: blue">Hello <a href="https://example.org"')
        ->toContain('src="https://app.test/inline/1/logo@example.org"')
        ->toContain('data-mailbox-cid="logo@example.org"')
        ->toContain('src="https://cdn.example.org/logo.png"')
        ->not->toContain('Title')
        ->not->toContain('color:red')
        ->not->toContain('tracker.test')
        ->not->toContain('script');
});

it('falls back to the escaped text part when there is no html', function (): void {
    expect(app(MessageHtml::class)->quote(null, "Line <one>\nLine two", fn (string $contentId): string => $contentId))
        ->toBe("Line &lt;one&gt;<br />\nLine two");
});

function quoteOf(string $html): string
{
    return html_entity_decode(app(MessageHtml::class)->quote(
        $html,
        null,
        fn (string $contentId): string => sprintf('https://app.test/inline/1/%s', $contentId),
    ));
}

it('removes active and tracking content from the quote', function (string $html, string $forbidden): void {
    expect(quoteOf($html))->not->toContain($forbidden);
})->with([
    'script' => [
        '<p>Hi</p><script>alert(1)</script>',
        'alert(1)',
    ],
    'event handler' => [
        '<p onclick="steal()">Hi</p><img src="https://cdn.example.org/a.png" onerror="steal()">',
        'steal()',
    ],
    'javascript link' => [
        '<a href="javascript:steal()">x</a>',
        'javascript:',
    ],
    'style url' => [
        '<div style="background: url(https://tracker.test/a.gif)">x</div>',
        'tracker.test',
    ],
    'plain http image' => [
        '<img src="http://tracker.test/a.gif">',
        'tracker.test',
    ],
    'srcset' => [
        '<img src="https://cdn.example.org/a.png" srcset="https://tracker.test/2x.png 2x">',
        'tracker.test',
    ],
    'forged inline marker' => [
        '<img src="https://cdn.example.org/a.png" data-mailbox-cid="other-part">',
        'other-part',
    ],
]);

it('escapes the content id it writes into the marker attribute', function (): void {
    $quote = app(MessageHtml::class)->quote(
        '<img src="cid:a&lt;b onload=&quot;steal()&quot;&gt;">',
        null,
        fn (string $contentId): string => 'https://app.test/inline/1/x',
    );

    $body = HtmlFragment::parse($quote);
    $images = HtmlFragment::elements($body, 'img');

    expect($images)->toHaveCount(1)
        ->and($images[0]->getAttribute('data-mailbox-cid'))->toBe('a<b onload="steal()">')
        ->and(HtmlFragment::elements($body, '[onload], b'))->toBe([]);
});

it('only rewrites real src attributes of inline images', function (): void {
    $quote = quoteOf('<img data-src="cid:lazy" alt="see src=&quot;cid:nope&quot;" src="https://cdn.example.org/a.png">');

    expect($quote)->toContain('src="https://cdn.example.org/a.png"')
        ->not->toContain('data-mailbox-cid')
        ->not->toContain('app.test/inline');
});

it('leaves no marked images or markers behind without a quote source', function (): void {
    $mailbox = mailbox([
        'append_sent' => false,
    ]);

    app(SendMessage::class)->handle($mailbox, [
        'body' => '<p>Hi</p><img src="https://app.test/inline/9/logo" data-mailbox-cid="logo"><img src="https://cdn.example.org/ok.png">',
        'subject' => 'Hi',
        'to' => [
            'colleague@example.org',
        ],
    ], null);

    $html = (string) test()->connector->transport->sent[0]->getHtmlBody();

    expect($html)->toBe('<p>Hi</p><img src="https://cdn.example.org/ok.png">');
});

it('keeps unrelated images and strips inline urls from their other attributes', function (): void {
    $mailbox = mailbox([
        'append_sent' => false,
    ]);
    $tag = '<img alt="src=&quot;https://app.test/inline/9/x&quot;" data-src="https://app.test/inline/9/y" src="https://cdn.example.org/ok.png">';

    app(SendMessage::class)->handle($mailbox, [
        'body' => $tag,
        'subject' => 'Hi',
        'to' => [
            'colleague@example.org',
        ],
    ], null);

    expect((string) test()->connector->transport->sent[0]->getHtmlBody())->toBe('<img src="https://cdn.example.org/ok.png">');
});

it('lists only inline parts that have a content id', function (): void {
    $mailbox = mailbox();
    $message = storedMessage(folder($mailbox), 1)->load('folder');
    $raw = (new Email)
        ->from('sender@example.org')
        ->to('team@example.com')
        ->html('<img src="cid:logo">')
        ->embed('LOGO', 'logo', 'image/png')
        ->attach('PDF', 'offer.pdf', 'application/pdf')
        ->toString();
    remoteFolder($mailbox, 'INBOX')->setMessages([
        new FakeMessage(1, [], $raw),
    ]);

    $parts = app(FetchMessageBody::class)->inlineAttachments($message);

    expect($parts)->toHaveCount(1)
        ->and(array_values($parts)[0]->contentType())->toBe('image/png');
});

it('marks only images whose real src is a cid reference', function (string $html, int $marked): void {
    $quote = app(MessageHtml::class)->quote(
        $html,
        null,
        fn (string $contentId): string => sprintf('https://app.test/inline/1/%s', $contentId),
    );
    $body = HtmlFragment::parse($quote);
    $markers = HtmlFragment::elements($body, '[data-mailbox-cid]');

    expect($markers)->toHaveCount($marked)
        ->and(substr_count($quote, 'app.test/inline'))->toBe($marked)
        ->and(trim($body->textContent))->not->toContain('app.test');

    foreach ($markers as $marker) {
        expect($marker->getAttribute('src'))->toBe(sprintf('https://app.test/inline/1/%s', $marker->getAttribute('data-mailbox-cid')));
    }
})->with([
    'slash before the marker attribute' => [
        '<img src="https://cdn.example.org/a.png" /data-mailbox-cid=other>',
        0,
    ],
    'duplicate src, first wins' => [
        '<img src="https://cdn.example.org/a.png" src="cid:logo">',
        0,
    ],
    'first src is the cid' => [
        '<img src="cid:logo" src="https://cdn.example.org/a.png">',
        1,
    ],
    'src text inside another attribute' => [
        '<img title=/src="cid:x" src="https://cdn.example.org/a.png">',
        0,
    ],
    'unquoted src with an equals sign' => [
        '<img src=cid:logo?size=2 alt=it\'s>',
        1,
    ],
    'placeholder-looking text' => [
        '<p>data:mailbox-inline-0 https://mailbox-inline.invalid/abc</p><img src="data:mailbox-inline-0">',
        0,
    ],
]);

it('never sends an inline route url', function (string $body): void {
    $mailbox = mailbox([
        'append_sent' => false,
    ]);

    app(SendMessage::class)->handle($mailbox, [
        'body' => $body,
        'subject' => 'Hi',
        'to' => [
            'colleague@example.org',
        ],
    ], null);

    $sent = test()->connector->transport->sent[0];

    expect((string) $sent->getHtmlBody())->not->toContain('app.test/inline')
        ->and((string) $sent->getTextBody())->not->toContain('app.test/inline');
})->with([
    'unquoted alt with an apostrophe' => [
        '<img alt=it\'s src=https://app.test/inline/9/logo?signature=a=b>',
    ],
    'link' => [
        '<p><a href="https://app.test/inline/9/logo?signature=abc">logo</a></p>',
    ],
    'text' => [
        '<p>see https://app.test/inline/9/logo?signature=abc please</p>',
    ],
    'background attribute' => [
        '<table background="https://app.test/inline/9/logo"><tr><td>x</td></tr></table>',
    ],
]);
