<?php

declare(strict_types=1);

use J87NL\Mailbox\Spam\SenderAuthentication;

beforeEach(function (): void {
    config()->set('mailbox.spam.trusted_authserv_ids', ['mx.example.com']);
});

it('trusts a sender only with a passing dmarc or an aligned dkim signature', function (?string $results, bool $passes): void {
    expect(app(SenderAuthentication::class)->passes('Boss@Mail.Example.org', $results))->toBe($passes);
})->with([
    'dmarc pass' => ['mx.example.com; spf=fail smtp.mailfrom=x.test; dmarc=pass (p=reject dis=none) header.from=mail.example.org', true],
    'dmarc pass without header.from' => ['mx.example.com; dmarc=pass', true],
    'dmarc pass for another domain' => ['mx.example.com; dmarc=pass header.from=evil.test', false],
    'dmarc fail' => ['mx.example.com; dmarc=fail header.from=mail.example.org', false],
    'dkim pass on the from domain' => ['mx.example.com; dkim=pass header.d=mail.example.org header.s=s1', true],
    'dkim pass on the parent domain' => ['mx.example.com; dkim=pass header.d=example.org', true],
    'dkim pass on another domain' => ['mx.example.com; dkim=pass header.d=evil.test', false],
    'dkim pass on a lookalike domain' => ['mx.example.com; dkim=pass header.d=le.org', false],
    'spf pass only' => ['mx.example.com; spf=pass smtp.mailfrom=mail.example.org', false],
    'empty' => ['', false],
    'missing' => [null, false],
    'garbage' => ['nonsense', false],
    'dkim pass on a bare suffix' => ['mx.example.com; dkim=pass header.d=org', false],
    'signer only inside a quoted reason' => ['mx.example.com; dkim=pass reason="header.d=example.org" header.d=evil.test', false],
    'pass only inside a nested comment' => ['mx.example.com; dkim=fail (x (dmarc=pass) y) header.d=example.org', false],
]);

it('only reads results from a trusted server', function (): void {
    $authentication = app(SenderAuthentication::class);

    expect($authentication->passes('boss@example.org', 'mx.example.com 1; dmarc=pass'))->toBeTrue()
        ->and($authentication->passes('boss@example.org', 'forged.test; dmarc=pass'))->toBeFalse();
});

it('trusts no result without trusted servers', function (array $trusted): void {
    config()->set('mailbox.spam.trusted_authserv_ids', $trusted);

    expect(app(SenderAuthentication::class)->passes('boss@example.org', 'mx.example.com; dmarc=pass header.from=example.org'))->toBeFalse();
})->with([
    'empty' => [[]],
    'blank' => [['  ']],
]);
