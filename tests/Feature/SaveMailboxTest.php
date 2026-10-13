<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Actions\SaveMailbox;
use J87NL\Mailbox\Contracts\ProvidesAccessToken;
use J87NL\Mailbox\Jobs\SyncMailboxJob;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAuditEntry;

beforeEach(function (): void {
    Bus::fake([SyncMailboxJob::class]);
});

/**
 * @return array<string, mixed>
 */
function mailboxData(array $overrides = []): array
{
    return [
        'active' => true,
        'append_sent' => true,
        'authentication' => 'plain',
        'email' => 'office@example.com',
        'imap_encryption' => 'ssl',
        'imap_host' => 'imap.example.com',
        'imap_password' => 'new-imap',
        'imap_port' => 993,
        'imap_username' => 'office@example.com',
        'name' => 'Office',
        'smtp_host' => 'smtp.example.com',
        'smtp_password' => 'new-smtp',
        'smtp_port' => 587,
        'smtp_username' => 'office@example.com',
        ...$overrides,
    ];
}

it('creates a mailbox after a passing connection test and queues a sync', function (): void {
    $mailbox = app(SaveMailbox::class)->handle(null, mailboxData(), user());

    expect($mailbox->exists)->toBeTrue()
        ->and($mailbox->imap_password)->toBe('new-imap')
        ->and(test()->connector->transport->started)->toBeFalse();

    Bus::assertDispatched(SyncMailboxJob::class);
});

it('stores nothing and reports errors per host when the connection fails', function (): void {
    test()->connector->failImap('Authentication failed');
    test()->connector->transport->failure = new RuntimeException('Connection timed out');

    try {
        app(SaveMailbox::class)->handle(null, mailboxData(), user());
        $this->fail('Expected a validation exception');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['imap_host', 'smtp_host'])
            ->and($exception->errors()['imap_host'][0])->toContain('Authentication failed');
    }

    expect(Mailbox::query()
        ->count())->toBe(0);
    Bus::assertNotDispatched(SyncMailboxJob::class);
});

it('keeps the stored passwords when the fields are left empty on edit', function (): void {
    $mailbox = mailbox();

    app(SaveMailbox::class)->handle($mailbox, mailboxData([
        'imap_password' => null,
        'imap_username' => 'team@example.com',
        'name' => 'Renamed',
        'smtp_password' => '',
        'smtp_username' => 'team@example.com',
    ]), user());

    $mailbox = Mailbox::query()
        ->find($mailbox->id);

    expect($mailbox->name)->toBe('Renamed')
        ->and($mailbox->imap_password)->toBe('imap-secret')
        ->and($mailbox->smtp_password)->toBe('smtp-secret');
});

it('only keeps stored passwords when editing, not when creating', function (): void {
    $mailbox = app(SaveMailbox::class)->handle(null, mailboxData(['imap_password' => '']), user(), test: false);

    expect($mailbox->imap_password)->toBe('');
});

it('requires the password again when the server of that protocol changes', function (array $changes, array $errors): void {
    $mailbox = mailbox();

    try {
        app(SaveMailbox::class)->handle($mailbox, mailboxData([
            'imap_password' => '',
            'imap_username' => 'team@example.com',
            'smtp_password' => '',
            'smtp_username' => 'team@example.com',
            ...$changes,
        ]), user());
        $this->fail('Expected a validation exception');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe($errors);
    }

    expect(Mailbox::query()
        ->find($mailbox->id)->imap_host)->toBe('imap.example.com')
        ->and(test()->connector->transport->started)->toBeFalse();
})->with([
    'imap host' => [['imap_host' => 'attacker.test'], ['imap_password']],
    'imap port' => [['imap_port' => 143], ['imap_password']],
    'smtp username' => [['smtp_username' => 'other@example.com'], ['smtp_password']],
    'both hosts' => [['imap_host' => 'attacker.test', 'smtp_host' => 'attacker.test'], ['imap_password', 'smtp_password']],
]);

it('accepts a server change when the password is entered again', function (): void {
    $mailbox = mailbox();

    app(SaveMailbox::class)->handle($mailbox, mailboxData([
        'imap_host' => 'new-imap.example.com',
        'imap_username' => 'team@example.com',
        'smtp_password' => '',
        'smtp_username' => 'team@example.com',
    ]), user());

    expect(Mailbox::query()
        ->find($mailbox->id)->only(['imap_host', 'imap_password']))->toBe([
            'imap_host' => 'new-imap.example.com',
            'imap_password' => 'new-imap',
        ]);
});

it('lists the filled fields in the audit entry of a new mailbox', function (): void {
    app(SaveMailbox::class)->handle(null, mailboxData(), user());

    $changed = MailboxAuditEntry::query()
        ->sole()->meta['changed'];

    expect($changed)->toContain('imap_host', 'name')
        ->not->toContain('imap_password', 'smtp_password');
});

it('only lets admins change the servers of an OAuth mailbox', function (?string $email, bool $allowed): void {
    config()->set('mailbox.access.admins', ['admin@example.com']);
    config()->set('mailbox.oauth_token_provider', OAuthTokenStub::class);
    $mailbox = mailbox([
        'authentication' => 'oauth',
        'imap_password' => null,
        'smtp_password' => null,
    ]);

    $attempt = fn () => app(SaveMailbox::class)->handle($mailbox, mailboxData([
        'authentication' => 'oauth',
        'imap_host' => 'attacker.test',
        'imap_password' => '',
        'imap_username' => 'team@example.com',
        'smtp_password' => '',
        'smtp_username' => 'team@example.com',
    ]), user(['email' => $email ?? 'manager@example.com']));

    if (! $allowed) {
        expect($attempt)->toThrow(ValidationException::class);
        expect(Mailbox::query()
            ->find($mailbox->id)->imap_host)->toBe('imap.example.com');

        return;
    }

    $attempt();

    expect(Mailbox::query()
        ->find($mailbox->id)->imap_host)->toBe('attacker.test');
})->with([
    'manager' => [null, false],
    'admin' => ['admin@example.com', true],
]);

class OAuthTokenStub implements ProvidesAccessToken
{
    public function accessToken(Mailbox $mailbox): string
    {
        return 'token';
    }
}
