<?php

declare(strict_types=1);

use J87NL\Mailbox\Html\MessageHtml;

function renderHtml(string $html, bool $allowRemoteImages = false): string
{
    return app(MessageHtml::class)->render(
        $html,
        null,
        fn (string $cid): string => sprintf('https://app.test/inline/%s?signature=abc', $cid),
        $allowRemoteImages,
    );
}

it('strips scripts, event handlers and javascript links', function (): void {
    $html = renderHtml('<p onclick="steal()">Hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a><iframe src="https://evil.test"></iframe>');

    expect($html)->toContain('<p>Hi</p>')
        ->not->toContain('script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('iframe');
});

it('opens links in a new tab without referrer', function (): void {
    expect(renderHtml('<a href="https://example.org">site</a>'))
        ->toContain('rel="noopener noreferrer nofollow"')
        ->toContain('target="_blank"');
});

it('blocks remote images and resource loading styles by default', function (): void {
    $html = renderHtml(implode('', [
        '<img src="https://tracker.test/pixel.gif">',
        '<div style="color: red; background: url(https://tracker.test/bg.gif)">a</div>',
        '<div style="background-image: u\\72l(https://tracker.test/escaped.gif)">b</div>',
        '<p style="background:url(&quot;https://tracker.test/quoted.gif&quot;)">c</p>',
    ]));

    expect($html)->not->toContain('tracker.test')
        ->toContain('style="color: red"');
});

it('keeps remote images and styles when the user allows them', function (): void {
    $html = renderHtml('<img src="https://cdn.test/logo.png"><div style="background: url(https://cdn.test/bg.png)">a</div>', true);

    expect($html)->toContain('https://cdn.test/logo.png')
        ->toContain('https://cdn.test/bg.png');
});

it('keeps remote images when blocking is switched off in config', function (): void {
    config()->set('mailbox.html.block_remote_images', false);

    expect(renderHtml('<img src="https://cdn.test/logo.png">'))->toContain('https://cdn.test/logo.png');
});

it('rewrites cid references to signed inline urls', function (): void {
    $html = html_entity_decode(renderHtml('<img src="cid:logo@example.org">'));

    expect($html)->toContain('src="https://app.test/inline/logo@example.org?signature=abc"')
        ->not->toContain('cid:');
});

it('renders the text part when there is no html part', function (): void {
    $html = app(MessageHtml::class)->render(null, "Line <one>\nLine two", fn (string $cid): string => $cid);

    expect($html)->toBe("<pre style=\"white-space: pre-wrap; font: inherit\">Line &lt;one&gt;\nLine two</pre>");
});

it('detects remote images in tags and in styles', function (string $html, bool $expected): void {
    expect(app(MessageHtml::class)->hasRemoteImages($html))->toBe($expected);
})->with([
    'img tag' => ['<img src="https://tracker.test/a.gif">', true],
    'css url' => ['<div style="background:url(\'https://tracker.test/a.gif\')"></div>', true],
    'inline cid' => ['<img src="cid:logo">', false],
    'plain link' => ['<a href="https://example.org">x</a>', false],
]);

it('keeps inline images but drops remote images of other hosts in the same message', function (): void {
    $html = html_entity_decode(renderHtml('<img src="cid:logo@example.org"><img src="https://tracker.test/pixel.gif"><img src="data:image/png;base64,AAAA">'));

    expect($html)->toContain('https://app.test/inline/logo@example.org')
        ->toContain('data:image/png;base64,AAAA')
        ->not->toContain('tracker.test');
});
