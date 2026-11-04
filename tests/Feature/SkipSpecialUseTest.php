<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Sync\SyncBudget;
use J87NL\Mailbox\Sync\SyncFolderMessages;
use J87NL\Mailbox\Sync\SyncMailbox;
use J87NL\Mailbox\Testing\RecordingFolder;

function gmailMailbox(): Mailbox
{
    $mailbox = mailbox();
    $remote = test()->connector->remote($mailbox);

    foreach ([
        '[Gmail]/Alle e-mail' => ['\\HasNoChildren', '\\All'],
        '[Gmail]/Belangrijk' => ['\\HasNoChildren', '\\Important'],
        '[Gmail]/Met ster' => ['\\HasNoChildren', '\\Flagged'],
        '[Gmail]/Verzonden berichten' => ['\\HasNoChildren', '\\Sent'],
        '[Gmail]/Prullenbak' => ['\\HasNoChildren', '\\Trash'],
        '[Gmail]/Spam' => ['\\HasNoChildren', '\\Junk'],
        'Klanten' => ['\\HasNoChildren'],
    ] as $path => $flags) {
        $remote->addFolder(new RecordingFolder($path, $flags));
        remoteFolder($mailbox, $path)->setMessages([remoteMessage(1)]);
    }

    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    return $mailbox;
}

function messageWithId(int $uid, string $messageId): FakeMessage
{
    $email = email(sprintf('Message %d', $uid));
    $email->getHeaders()->addIdHeader('Message-ID', $messageId);

    return remoteMessage($uid, email: $email);
}

function messagesPerFolder(): array
{
    return MailboxFolder::query()
        ->withCount('messages')
        ->orderBy('path')
        ->pluck('messages_count', 'path')
        ->all();
}

it('syncs All Mail like any other folder but not Important and Starred', function (): void {
    $mailbox = gmailMailbox();
    remoteFolder($mailbox, '[Gmail]/Alle e-mail')->status = ['MESSAGES' => 1, 'UIDVALIDITY' => 1, 'UNSEEN' => 1];

    app(SyncMailbox::class)->handle($mailbox);

    expect(messagesPerFolder())->toBe([
        'INBOX' => 1,
        'Klanten' => 1,
        '[Gmail]/Alle e-mail' => 1,
        '[Gmail]/Belangrijk' => 0,
        '[Gmail]/Met ster' => 0,
        '[Gmail]/Prullenbak' => 1,
        '[Gmail]/Spam' => 1,
        '[Gmail]/Verzonden berichten' => 1,
    ]);

    $allMail = MailboxFolder::query()
        ->where('path', '[Gmail]/Alle e-mail')
        ->sole();

    expect($allMail->role)->toBe(FolderRole::Archive)
        ->and($allMail->synced_at)->not->toBeNull()
        ->and($allMail->unseen_count)->toBe(1)
        ->and($allMail->skipsSync())->toBeFalse();
});

it('fetches only the sync window on the first sync of All Mail, also when it was skipped before', function (): void {
    config()->set('mailbox.sync.initial_days', 30);
    $mailbox = gmailMailbox();
    $allMail = folder($mailbox, '[Gmail]/Alle e-mail', FolderRole::Archive, ['flags' => ['\\All']]);

    app(SyncFolderMessages::class)->handle($allMail);

    expect(remoteFolder($mailbox, '[Gmail]/Alle e-mail')->searches)->toBe([
        sprintf('SINCE %s', now()->subDays(30)->format('d-M-Y')),
    ])
        ->and($allMail->refresh()->last_uid)->toBe(1);
});

it('continues All Mail from its last uid once it was synced', function (): void {
    $mailbox = gmailMailbox();
    $allMail = folder($mailbox, '[Gmail]/Alle e-mail', FolderRole::Archive, ['flags' => ['\\All'], 'last_uid' => 1, 'uid_validity' => 1, 'synced_at' => now()->subHour()]);
    remoteFolder($mailbox, '[Gmail]/Alle e-mail')->status = ['UIDVALIDITY' => 1];
    remoteFolder($mailbox, '[Gmail]/Alle e-mail')->setMessages([remoteMessage(1), remoteMessage(2)]);

    app(SyncFolderMessages::class)->handle($allMail);

    expect(remoteFolder($mailbox, '[Gmail]/Alle e-mail')->searches)->toBe(['UID 2:4294967295'])
        ->and($allMail->messages()->pluck('uid')->all())->toBe([2]);
});

it('starts All Mail over within the sync window when its last sync is older than the window', function (): void {
    config()->set('mailbox.sync.initial_days', 30);
    $mailbox = gmailMailbox();
    $allMail = folder($mailbox, '[Gmail]/Alle e-mail', FolderRole::Archive, ['flags' => ['\\All'], 'last_uid' => 5, 'uid_validity' => 1, 'synced_at' => now()->subDays(31), 'older_exhausted' => true]);
    storedMessage($allMail, 5);
    remoteFolder($mailbox, '[Gmail]/Alle e-mail')->status = ['UIDVALIDITY' => 1];
    remoteFolder($mailbox, '[Gmail]/Alle e-mail')->setMessages([remoteMessage(5), remoteMessage(9)]);

    app(SyncFolderMessages::class)->handle($allMail);

    expect(remoteFolder($mailbox, '[Gmail]/Alle e-mail')->searches)->toBe([
        sprintf('SINCE %s', now()->subDays(30)->format('d-M-Y')),
    ])
        ->and($allMail->refresh()->last_uid)->toBe(9)
        ->and($allMail->older_exhausted)->toBeFalse()
        ->and($allMail->synced_at)->not->toBeNull();
});

it('keeps what a skipped folder cached before and never asks for a sync of it', function (): void {
    $mailbox = gmailMailbox();
    $important = folder($mailbox, '[Gmail]/Belangrijk', FolderRole::Custom, ['flags' => ['\\Important'], 'last_uid' => 1, 'uid_validity' => 1]);
    storedMessage($important, 1);
    remoteFolder($mailbox, '[Gmail]/Belangrijk')->setMessages([]);

    app(SyncFolderMessages::class)->handle($important);

    expect($important->messages()->pluck('uid')->all())->toBe([1])
        ->and($important->refresh()->needsSync())->toBeFalse();
});

it('leaves skipped folders out of the deferred work and clears their unseen count', function (): void {
    $mailbox = gmailMailbox();
    $important = folder($mailbox, '[Gmail]/Belangrijk', FolderRole::Custom, ['flags' => ['\\Important'], 'unseen_count' => 4]);
    remoteFolder($mailbox, 'INBOX')->secondsPerChunk = 60;
    Log::spy();

    app(SyncMailbox::class)->handle($mailbox, SyncBudget::seconds(10));

    expect($important->refresh()->unseen_count)->toBe(0);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => ! array_key_exists('[Gmail]/Belangrijk', $context['deferred'])
            && ! array_key_exists('[Gmail]/Met ster', $context['deferred'])
            && ($context['deferred']['[Gmail]/Alle e-mail'] ?? null) === SyncBudget::NOT_REACHED
            && ($context['deferred']['Klanten'] ?? null) === SyncBudget::NOT_REACHED);
});

it('skips All Mail again when it is listed in skip_special_use', function (): void {
    config()->set('mailbox.sync.skip_special_use', ['\\All', '\\Important', '\\Flagged']);
    $mailbox = gmailMailbox();

    app(SyncMailbox::class)->handle($mailbox);

    expect(messagesPerFolder()['[Gmail]/Alle e-mail'])->toBe(0)
        ->and(MailboxFolder::query()
            ->where('path', '[Gmail]/Alle e-mail')
            ->sole()->synced_at)->toBeNull();
});

it('syncs every folder when no special-use attributes are skipped', function (): void {
    config()->set('mailbox.sync.skip_special_use', []);
    $mailbox = gmailMailbox();

    app(SyncMailbox::class)->handle($mailbox);

    expect(array_values(array_unique(messagesPerFolder())))->toBe([1]);
});

it('announces a new mail once from the inbox and never from its copy in All Mail', function (array $order): void {
    Event::fake([MessageReceived::class]);
    $mailbox = gmailMailbox();
    $inbox = folder($mailbox, 'INBOX', FolderRole::Inbox, ['last_uid' => 1, 'uid_validity' => 1, 'synced_at' => now()]);
    $allMail = folder($mailbox, '[Gmail]/Alle e-mail', FolderRole::Archive, ['flags' => ['\\All'], 'last_uid' => 1, 'uid_validity' => 1, 'synced_at' => now()]);
    $sent = folder($mailbox, '[Gmail]/Verzonden berichten', FolderRole::Sent, ['flags' => ['\\Sent'], 'last_uid' => 1, 'uid_validity' => 1, 'synced_at' => now()]);
    foreach (['INBOX', '[Gmail]/Alle e-mail', '[Gmail]/Verzonden berichten'] as $path) {
        remoteFolder($mailbox, $path)->status = ['UIDVALIDITY' => 1];
    }
    remoteFolder($mailbox, '[Gmail]/Alle e-mail')->setMessages([
        messageWithId(2, 'new@example.com'),
        messageWithId(3, 'sent@example.com'),
    ]);
    remoteFolder($mailbox, 'INBOX')->setMessages([messageWithId(2, 'new@example.com')]);
    remoteFolder($mailbox, '[Gmail]/Verzonden berichten')->setMessages([messageWithId(2, 'sent@example.com')]);

    $folders = [
        'all' => $allMail,
        'inbox' => $inbox,
        'sent' => $sent,
    ];

    foreach ($order as $key) {
        app(SyncFolderMessages::class)->handle($folders[$key]->refresh());
    }

    Event::assertDispatchedTimes(MessageReceived::class, 1);
    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event): bool => $event->message->mailbox_folder_id === $inbox->id);
    expect(MailboxAuditEntry::query()
        ->where('action', AuditAction::Received)
        ->pluck('folder_path')
        ->all())->toBe(['INBOX']);
})->with([
    'All Mail first' => [['all', 'inbox', 'sent']],
    'inbox first' => [['inbox', 'sent', 'all']],
]);
