<?php

declare(strict_types=1);

use J87NL\Mailbox\Enums\SpamAllowlistSource;
use J87NL\Mailbox\Models\MailboxSpamAllowlistEntry;
use J87NL\Mailbox\Spam\SpamAllowlist;

it('matches a sender on its address or its domain, for its own mailbox or every mailbox', function (): void {
    $mailbox = mailbox();
    $other = mailbox(['email' => 'other@example.com']);
    $allowlist = app(SpamAllowlist::class);

    $allowlist->add($mailbox, ' Boss@Example.org ');
    $allowlist->add(null, '@partner.example');
    $allowlist->add($other, 'friend@example.net');

    expect($allowlist->matches($mailbox, 'BOSS@example.org'))->toBeTrue()
        ->and($allowlist->matches($mailbox, 'someone@partner.example'))->toBeTrue()
        ->and($allowlist->matches($mailbox, 'someone@sub.partner.example'))->toBeFalse()
        ->and($allowlist->matches($mailbox, 'friend@example.net'))->toBeFalse()
        ->and($allowlist->matches($mailbox, null))->toBeFalse()
        ->and($allowlist->allowedSenders($mailbox, collect(['boss@example.org', 'x@partner.example', 'spam@bad.example']))->all())
        ->toBe(['boss@example.org', 'x@partner.example']);
});

it('keeps one entry per pattern and mailbox and removes it again', function (): void {
    $mailbox = mailbox();
    $allowlist = app(SpamAllowlist::class);

    $allowlist->add($mailbox, 'boss@example.org', SpamAllowlistSource::NotJunk);
    $allowlist->add($mailbox, 'BOSS@example.org');

    expect(MailboxSpamAllowlistEntry::query()->sole()->source)->toBe(SpamAllowlistSource::NotJunk)
        ->and($allowlist->remove($mailbox, 'Boss@example.org'))->toBeTrue()
        ->and(MailboxSpamAllowlistEntry::query()->count())->toBe(0);
});
