<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use J87NL\Mailbox\Actions\SendMessage;
use J87NL\Mailbox\Contracts\ResolvesInlineImageUrls;
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
        ->toContain('src="https://app.test/inline/1/logo@example.org" data-mailbox-cid="logo@example.org"')
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
