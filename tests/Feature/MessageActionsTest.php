<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use Illuminate\Support\Facades\Bus;
use J87NL\Mailbox\Actions\ArchiveMessages;
use J87NL\Mailbox\Actions\DeleteMessages;
use J87NL\Mailbox\Actions\MarkMessages;
use J87NL\Mailbox\Actions\MoveMessages;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;

beforeEach(function (): void {
    Bus::fake([SyncFolderJob::class]);
});

it('moves messages with one UID MOVE per folder and audits them', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    $target = folder($mailbox, 'Projects', FolderRole::Custom);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1), remoteMessage(2), remoteMessage(3)]);
    $messages = collect([storedMessage($inbox, 1), storedMessage($inbox, 2)]);
    $actor = user();

    $moved = app(MoveMessages::class)->handle($messages, $target, $actor);

    expect($moved)->toBe(2)
        ->and(remoteFolder($mailbox, 'INBOX')->operations)->toBe([
            ['target' => 'Projects', 'type' => 'move', 'uids' => [1, 2]],
        ])
        ->and(MailboxMessage::query()->count())->toBe(0)
        ->and(MailboxAuditEntry::query()->get(['action', 'folder_path', 'target_path', 'user_id'])->toArray())->toBe([
            ['action' => 'moved', 'folder_path' => 'INBOX', 'target_path' => 'Projects', 'user_id' => $actor->id],
            ['action' => 'moved', 'folder_path' => 'INBOX', 'target_path' => 'Projects', 'user_id' => $actor->id],
        ]);

    Bus::assertDispatched(SyncFolderJob::class, fn (SyncFolderJob $job): bool => $job->folderId === $target->id);
});

it('does not queue a sync when nothing moved', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);

    $moved = app(MoveMessages::class)->handle(collect([storedMessage($inbox, 1)]), $inbox, null);

    expect($moved)->toBe(0);
    Bus::assertNotDispatched(SyncFolderJob::class);
});

it('archives into the archive folder and creates the fallback when there is none', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    app(ArchiveMessages::class)->handle($mailbox, collect([storedMessage($inbox, 1)]), null);

    $archive = MailboxFolder::query()->where('role', FolderRole::Archive)->sole();

    expect($archive->path)->toBe('Archive')
        ->and(remoteFolder($mailbox, 'INBOX')->operations[0]['target'])->toBe('Archive')
        ->and(MailboxAuditEntry::query()->sole()->action)->toBe(AuditAction::Archived);
});

it('moves deleted messages outside the trash to the trash', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    folder($mailbox, 'Trash', FolderRole::Trash);
    remoteFolder($mailbox, 'Trash');
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(4)]);

    $count = app(DeleteMessages::class)->handle($mailbox, collect([storedMessage($inbox, 4)]), null);

    expect($count)->toBe(1)
        ->and(remoteFolder($mailbox, 'INBOX')->operations)->toBe([
            ['target' => 'Trash', 'type' => 'move', 'uids' => [4]],
        ])
        ->and(test()->connector->expunged)->toBe([])
        ->and(MailboxAuditEntry::query()->sole()->action)->toBe(AuditAction::Trashed);
});

it('purges in the trash with UID EXPUNGE of only our uids', function (): void {
    $mailbox = mailbox();
    $trash = folder($mailbox, 'Trash', FolderRole::Trash);
    remoteFolder($mailbox, 'Trash')->setMessages([
        remoteMessage(7),
        remoteMessage(8, [ImapFlag::Deleted->value]),
    ]);

    app(DeleteMessages::class)->handle($mailbox, collect([storedMessage($trash, 7)]), null);

    $remaining = collect(remoteFolder($mailbox, 'Trash')->getMessages())->map->uid()->all();

    expect(test()->connector->expunged)->toBe([['folder' => 'Trash', 'uids' => [7]]])
        ->and($remaining)->toBe([8])
        ->and(MailboxMessage::query()->count())->toBe(0)
        ->and(MailboxAuditEntry::query()->sole()->action)->toBe(AuditAction::Deleted);
});

it('only flags deleted without expunging when the server lacks UIDPLUS', function (): void {
    test()->connector->supportsUidPlus = false;
    $mailbox = mailbox();
    $trash = folder($mailbox, 'Trash', FolderRole::Trash);
    remoteFolder($mailbox, 'Trash')->setMessages([remoteMessage(7), remoteMessage(8)]);

    app(DeleteMessages::class)->handle($mailbox, collect([storedMessage($trash, 7)]), null);

    $remote = remoteFolder($mailbox, 'Trash');

    expect(count($remote->getMessages()))->toBe(2)
        ->and($remote->message(7)->flags())->toContain(ImapFlag::Deleted->value)
        ->and($remote->message(8)->flags())->not->toContain(ImapFlag::Deleted->value)
        ->and(test()->connector->expunged)->toBe([])
        ->and(MailboxMessage::query()->count())->toBe(0);
});

it('marks messages read, unread, flagged and unflagged on the server and in the cache', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1), remoteMessage(2)]);
    $messages = MailboxMessage::query()->with('folder')->findMany([
        storedMessage($inbox, 1)->id,
        storedMessage($inbox, 2)->id,
    ]);
    $mark = app(MarkMessages::class);

    $mark->read($messages, null);
    $mark->flag($messages, null);
    $mark->unflag($messages->take(1), null);

    $remote = remoteFolder($mailbox, 'INBOX');

    expect($remote->message(1)->flags())->toBe([ImapFlag::Seen->value])
        ->and(array_values($remote->message(2)->flags()))->toBe([ImapFlag::Seen->value, ImapFlag::Flagged->value])
        ->and(MailboxMessage::query()->orderBy('uid')->get(['is_seen', 'is_flagged'])->toArray())->toBe([
            ['is_seen' => true, 'is_flagged' => false],
            ['is_seen' => true, 'is_flagged' => true],
        ])
        ->and(MailboxAuditEntry::query()->count())->toBe(5);

    $mark->unread($messages, null);

    expect(MailboxMessage::query()->where('is_seen', true)->count())->toBe(0);
});
