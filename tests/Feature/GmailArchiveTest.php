<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use J87NL\Mailbox\Actions\ArchiveMessages;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxMessage;

beforeEach(function (): void {
    Bus::fake([SyncFolderJob::class]);
});

function gmail(): Mailbox
{
    $mailbox = mailbox();
    test()->connector->remote($mailbox)->setCapabilities([
        'IMAP4rev1',
        'UIDPLUS',
        'MOVE',
        'X-GM-EXT-1',
    ]);

    return $mailbox;
}

it('archives from the Gmail inbox by moving the mail to All Mail and syncs All Mail', function (): void {
    $mailbox = gmail();
    $inbox = folder($mailbox);
    $allMail = folder($mailbox, '[Gmail]/All Mail', FolderRole::Archive, ['flags' => ['\\HasNoChildren', '\\All']]);
    remoteFolder($mailbox, '[Gmail]/All Mail');
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1), remoteMessage(2)]);

    $archived = app(ArchiveMessages::class)->handle($mailbox, collect([storedMessage($inbox, 1)->load('folder')]), null);

    expect($archived)->toBe(1)
        ->and(remoteFolder($mailbox, 'INBOX')->operations)->toBe([
            [
                'target' => '[Gmail]/All Mail',
                'type' => 'move',
                'uids' => [1],
            ],
        ])
        ->and(MailboxMessage::query()
            ->count())->toBe(0)
        ->and(MailboxAuditEntry::query()
            ->sole()
            ->only([
                'action',
                'target_path',
            ]))->toBe([
                'action' => AuditAction::Archived,
                'target_path' => '[Gmail]/All Mail',
            ]);

    Bus::assertDispatched(SyncFolderJob::class, fn (SyncFolderJob $job): bool => $job->folderId === $allMail->id);
});

it('moves Gmail inbox mail to the archive fallback when All Mail is hidden from IMAP', function (): void {
    $mailbox = gmail();
    $inbox = folder($mailbox);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    app(ArchiveMessages::class)->handle($mailbox, collect([storedMessage($inbox, 1)->load('folder')]), null);

    expect(remoteFolder($mailbox, 'INBOX')->operations[0]['target'])->toBe('Archive')
        ->and(MailboxAuditEntry::query()
            ->sole()->target_path)->toBe('Archive');
});

it('still moves Gmail messages outside the inbox to All Mail and syncs All Mail', function (): void {
    $mailbox = gmail();
    $label = folder($mailbox, 'Klanten', FolderRole::Custom);
    $allMail = folder($mailbox, '[Gmail]/All Mail', FolderRole::Archive, ['flags' => ['\\All']]);
    remoteFolder($mailbox, '[Gmail]/All Mail');
    remoteFolder($mailbox, 'Klanten')->setMessages([remoteMessage(3)]);

    app(ArchiveMessages::class)->handle($mailbox, collect([storedMessage($label, 3)->load('folder')]), null);

    expect(remoteFolder($mailbox, 'Klanten')->operations)->toBe([
        [
            'target' => '[Gmail]/All Mail',
            'type' => 'move',
            'uids' => [3],
        ],
    ]);

    Bus::assertDispatched(SyncFolderJob::class, fn (SyncFolderJob $job): bool => $job->folderId === $allMail->id);
});

it('keeps moving to the archive folder on servers without the Gmail extension', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    $archive = folder($mailbox, 'Archive', FolderRole::Archive);
    remoteFolder($mailbox, 'Archive');
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    app(ArchiveMessages::class)->handle($mailbox, collect([storedMessage($inbox, 1)->load('folder')]), null);

    expect(remoteFolder($mailbox, 'INBOX')->operations[0]['target'])->toBe('Archive');

    Bus::assertDispatched(SyncFolderJob::class, fn (SyncFolderJob $job): bool => $job->folderId === $archive->id);
});

it('queues no sync of All Mail when it is skipped', function (): void {
    config()->set('mailbox.sync.skip_special_use', ['\\All']);
    $mailbox = gmail();
    $inbox = folder($mailbox);
    folder($mailbox, '[Gmail]/All Mail', FolderRole::Archive, ['flags' => ['\\All']]);
    remoteFolder($mailbox, '[Gmail]/All Mail');
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    app(ArchiveMessages::class)->handle($mailbox, collect([storedMessage($inbox, 1)->load('folder')]), null);

    expect(remoteFolder($mailbox, 'INBOX')->operations[0]['target'])->toBe('[Gmail]/All Mail');

    Bus::assertNotDispatched(SyncFolderJob::class);
});
