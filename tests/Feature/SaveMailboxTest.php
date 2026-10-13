<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Actions\SaveMailbox;
use J87NL\Mailbox\Jobs\SyncMailboxJob;
use J87NL\Mailbox\Models\Mailbox;

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

    expect(Mailbox::query()->count())->toBe(0);
    Bus::assertNotDispatched(SyncMailboxJob::class);
});

it('keeps the stored passwords when the fields are left empty on edit', function (): void {
    $mailbox = mailbox();

    app(SaveMailbox::class)->handle($mailbox, mailboxData([
        'imap_password' => null,
        'name' => 'Renamed',
        'smtp_password' => '',
    ]), user());

    $mailbox = Mailbox::query()->find($mailbox->id);

    expect($mailbox->name)->toBe('Renamed')
        ->and($mailbox->imap_password)->toBe('imap-secret')
        ->and($mailbox->smtp_password)->toBe('smtp-secret');
});

it('only keeps stored passwords when editing, not when creating', function (): void {
    $mailbox = app(SaveMailbox::class)->handle(null, mailboxData(['imap_password' => '']), user(), test: false);

    expect($mailbox->imap_password)->toBe('');
});
