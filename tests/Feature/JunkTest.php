<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use J87NL\Mailbox\Actions\MarkJunk;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;

beforeEach(function (): void {
    Bus::fake([SyncFolderJob::class]);
});

it('sets the junk keyword before moving messages to the junk folder', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    $junk = folder($mailbox, 'Spam', FolderRole::Junk);
    remoteFolder($mailbox, 'Spam');
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1, ['$NotJunk']), remoteMessage(2)]);
    $actor = user();

    $moved = app(MarkJunk::class)->junk($mailbox, MailboxMessage::query()->with('folder')->findMany([
        storedMessage($inbox, 1)->id,
    ]), $actor);

    expect($moved)->toBe(1)
        ->and(remoteFolder($mailbox, 'INBOX')->operations)->toBe([
            [
                'flag' => '$Junk',
                'operation' => '+',
                'type' => 'flag',
                'uids' => [1],
            ],
            [
                'flag' => '$NotJunk',
                'operation' => '-',
                'type' => 'flag',
                'uids' => [1],
            ],
            [
                'target' => 'Spam',
                'type' => 'move',
                'uids' => [1],
            ],
        ])
        ->and(MailboxAuditEntry::query()
            ->sole()
            ->only([
                'action',
                'target_path',
                'user_id',
            ]))->toBe([
                'action' => AuditAction::MarkedJunk,
                'target_path' => 'Spam',
                'user_id' => $actor->id,
            ]);

    Bus::assertDispatched(SyncFolderJob::class, fn (SyncFolderJob $job): bool => $job->folderId === $junk->id);
});

it('creates the junk fallback folder when the mailbox has none', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    app(MarkJunk::class)->junk($mailbox, collect([storedMessage($inbox, 1)->load('folder')]), null);

    expect(MailboxFolder::query()
        ->where('role', FolderRole::Junk)
        ->sole()->path)->toBe('Junk')
        ->and(remoteFolder($mailbox, 'INBOX')->operations[2]['target'])->toBe('Junk');
});

it('moves messages that are not junk back to the inbox with the not junk keyword', function (): void {
    $mailbox = mailbox();
    folder($mailbox);
    $junk = folder($mailbox, 'Spam', FolderRole::Junk);
    remoteFolder($mailbox, 'Spam')->setMessages([remoteMessage(5, ['$Junk'])]);

    $moved = app(MarkJunk::class)->notJunk($mailbox, collect([storedMessage($junk, 5)->load('folder')]), null);

    expect($moved)->toBe(1)
        ->and(remoteFolder($mailbox, 'Spam')->operations)->toBe([
            [
                'flag' => '$NotJunk',
                'operation' => '+',
                'type' => 'flag',
                'uids' => [5],
            ],
            [
                'flag' => '$Junk',
                'operation' => '-',
                'type' => 'flag',
                'uids' => [5],
            ],
            [
                'target' => 'INBOX',
                'type' => 'move',
                'uids' => [5],
            ],
        ])
        ->and(MailboxAuditEntry::query()
            ->sole()->action)->toBe(AuditAction::MarkedNotJunk);
});

it('leaves messages that are already in the junk folder alone', function (): void {
    $mailbox = mailbox();
    $junk = folder($mailbox, 'Spam', FolderRole::Junk);
    remoteFolder($mailbox, 'Spam')->setMessages([remoteMessage(5)]);

    $moved = app(MarkJunk::class)->junk($mailbox, collect([storedMessage($junk, 5)->load('folder')]), null);

    expect($moved)->toBe(0)
        ->and(remoteFolder($mailbox, 'Spam')->operations)->toBe([])
        ->and(MailboxAuditEntry::query()
            ->count())->toBe(0);
});

it('turns the keywords back when the move fails', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    folder($mailbox, 'Spam', FolderRole::Junk);
    remoteFolder($mailbox, 'Spam');
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);
    remoteFolder($mailbox, 'INBOX')->moveFailure = new RuntimeException('NO');

    expect(fn () => app(MarkJunk::class)->junk($mailbox, collect([storedMessage($inbox, 1)->load('folder')]), user()))
        ->toThrow(RuntimeException::class)
        ->and(collect(remoteFolder($mailbox, 'INBOX')->operations)->map(fn (array $operation): string => sprintf('%s%s', $operation['operation'], $operation['flag']))->all())
        ->toBe(['+$Junk', '-$NotJunk', '-$Junk']);
});
