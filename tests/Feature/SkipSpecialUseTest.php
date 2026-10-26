<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
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

function messagesPerFolder(): array
{
    return MailboxFolder::query()
        ->withCount('messages')
        ->orderBy('path')
        ->pluck('messages_count', 'path')
        ->all();
}

it('does not sync the folders that only repeat mail from the inbox and labels', function (): void {
    $mailbox = gmailMailbox();

    app(SyncMailbox::class)->handle($mailbox);

    expect(messagesPerFolder())->toBe([
        'INBOX' => 1,
        'Klanten' => 1,
        '[Gmail]/Alle e-mail' => 0,
        '[Gmail]/Belangrijk' => 0,
        '[Gmail]/Met ster' => 0,
        '[Gmail]/Prullenbak' => 1,
        '[Gmail]/Spam' => 1,
        '[Gmail]/Verzonden berichten' => 1,
    ])
        ->and(MailboxFolder::query()
            ->where('path', '[Gmail]/Alle e-mail')
            ->sole()
            ->only(['role', 'selectable', 'synced_at']))->toBe([
                'role' => FolderRole::Archive,
                'selectable' => true,
                'synced_at' => null,
            ]);
});

it('keeps what a skipped folder cached before and never asks for a sync of it', function (): void {
    $mailbox = gmailMailbox();
    $allMail = folder($mailbox, '[Gmail]/Alle e-mail', FolderRole::Archive, ['flags' => ['\\All'], 'last_uid' => 1, 'uid_validity' => 1]);
    storedMessage($allMail, 1);
    remoteFolder($mailbox, '[Gmail]/Alle e-mail')->setMessages([]);

    app(SyncFolderMessages::class)->handle($allMail);

    expect($allMail->messages()->pluck('uid')->all())->toBe([1])
        ->and($allMail->refresh()->needsSync())->toBeFalse();
});

it('leaves skipped folders out of the deferred work and clears their unseen count', function (): void {
    $mailbox = gmailMailbox();
    $allMail = folder($mailbox, '[Gmail]/Alle e-mail', FolderRole::Archive, ['flags' => ['\\All'], 'unseen_count' => 4]);
    remoteFolder($mailbox, 'INBOX')->secondsPerChunk = 60;
    Log::spy();

    app(SyncMailbox::class)->handle($mailbox, SyncBudget::seconds(10));

    expect($allMail->refresh()->unseen_count)->toBe(0);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => ! array_key_exists('[Gmail]/Alle e-mail', $context['deferred'])
            && ! array_key_exists('[Gmail]/Belangrijk', $context['deferred'])
            && ($context['deferred']['Klanten'] ?? null) === SyncBudget::NOT_REACHED);
});

it('syncs every folder when no special-use attributes are skipped', function (): void {
    config()->set('mailbox.sync.skip_special_use', []);
    $mailbox = gmailMailbox();

    app(SyncMailbox::class)->handle($mailbox);

    expect(array_values(array_unique(messagesPerFolder())))->toBe([1]);
});
