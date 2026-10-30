<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use J87NL\Mailbox\Actions\FetchOlderMessages;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Jobs\FetchOlderMessagesJob;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Sync\SyncFolderMessages;

/**
 * @return array<int, int>
 */
function storedUids(): array
{
    return MailboxMessage::query()
        ->orderBy('uid')
        ->pluck('uid')
        ->all();
}

it('fetches the newest batch of an empty folder', function (): void {
    config()->set('mailbox.sync.older_batch_size', 2);
    $mailbox = mailbox();
    $folder = folder($mailbox);
    remoteFolder($mailbox, 'INBOX')->setMessages([
        remoteMessage(1),
        remoteMessage(2),
        remoteMessage(3),
    ]);

    $stored = app(FetchOlderMessages::class)->handle($folder);

    expect($stored)->toBe(2)
        ->and(storedUids())->toBe([2, 3])
        ->and($folder->refresh()->last_uid)->toBe(0);
});

it('stays below the last known uid when a synced folder holds no messages', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 2, 'synced_at' => now()]);
    remoteFolder($mailbox, 'INBOX')->setMessages([
        remoteMessage(1),
        remoteMessage(2),
        remoteMessage(3),
    ]);

    app(FetchOlderMessages::class)->handle($folder);

    expect(storedUids())->toBe([1, 2])
        ->and($folder->refresh()->last_uid)->toBe(2);
});

it('fetches only messages older than the oldest stored message, newest first', function (): void {
    config()->set('mailbox.sync.older_batch_size', 2);
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 10, 'synced_at' => now()]);
    storedMessage($folder, 8);
    storedMessage($folder, 10);
    remoteFolder($mailbox, 'INBOX')->setMessages([
        remoteMessage(3),
        remoteMessage(5),
        remoteMessage(7),
        remoteMessage(8),
        remoteMessage(10),
        remoteMessage(12),
    ]);

    $stored = app(FetchOlderMessages::class)->handle($folder);

    expect($stored)->toBe(2)
        ->and(storedUids())->toBe([5, 7, 8, 10])
        ->and($folder->refresh()->last_uid)->toBe(10);
});

it('skips messages flagged deleted', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 5]);
    storedMessage($folder, 5);
    remoteFolder($mailbox, 'INBOX')->setMessages([
        remoteMessage(3),
        remoteMessage(4, [ImapFlag::Deleted->value]),
        remoteMessage(5),
    ]);

    app(FetchOlderMessages::class)->handle($folder);

    expect(storedUids())->toBe([3, 5]);
});

it('stops when nothing older is left', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 4]);
    storedMessage($folder, 1);
    storedMessage($folder, 4);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->setMessages([
        remoteMessage(1),
        remoteMessage(4),
    ]);

    $stored = app(FetchOlderMessages::class)->handle($folder);

    expect($stored)->toBe(0)
        ->and($remote->fetches)->toBe(0)
        ->and(storedUids())->toBe([1, 4]);
});

it('never announces older messages', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 9, 'synced_at' => now()]);
    storedMessage($folder, 9);
    remoteFolder($mailbox, 'INBOX')->setMessages([
        remoteMessage(2),
        remoteMessage(9),
    ]);

    app(FetchOlderMessages::class)->handle($folder);

    expect(storedUids())->toBe([2, 9]);
    Event::assertNotDispatched(MessageReceived::class);
});

it('leaves folders alone that are not synced', function (): void {
    $mailbox = mailbox();
    $allMail = folder($mailbox, 'All Mail', FolderRole::Custom, ['flags' => ['\\All']]);
    $parent = folder($mailbox, 'Parent', FolderRole::Custom, ['selectable' => false]);
    remoteFolder($mailbox, 'All Mail')->setMessages([remoteMessage(1)]);
    remoteFolder($mailbox, 'Parent')->setMessages([remoteMessage(1)]);

    expect(app(FetchOlderMessages::class)->handle($allMail))->toBe(0)
        ->and(app(FetchOlderMessages::class)->handle($parent))->toBe(0)
        ->and(storedUids())->toBe([]);
});

it('waits for a running sync of the same folder', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);
    $lock = Cache::lock(sprintf(SyncFolderMessages::LOCK_KEY, $folder->id), 60);
    $lock->get();

    $stored = app(FetchOlderMessages::class)->handle($folder);

    $lock->release();

    expect($stored)->toBeNull()
        ->and(storedUids())->toBe([]);
});

it('fetches older messages from the queued job', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    FetchOlderMessagesJob::dispatchSync($folder->id);

    expect(storedUids())->toBe([1]);
});

it('stores the message count of the folder from the server status', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['MESSAGES' => 240, 'UIDVALIDITY' => 1, 'UNSEEN' => 3];
    $remote->setMessages([remoteMessage(1)]);

    app(SyncFolderMessages::class)->handle($folder);

    expect($folder->refresh()->message_count)->toBe(240);
});

it('moves past a batch of deleted messages on the next call', function (): void {
    config()->set('mailbox.sync.older_batch_size', 2);
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 6]);
    storedMessage($folder, 6);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->setMessages([
        remoteMessage(2),
        remoteMessage(3),
        remoteMessage(4, [ImapFlag::Deleted->value]),
        remoteMessage(5, [ImapFlag::Deleted->value]),
        remoteMessage(6),
    ]);

    expect(app(FetchOlderMessages::class)->handle($folder))->toBe(0)
        ->and($folder->refresh()->only(['oldest_uid', 'older_exhausted']))->toBe(['oldest_uid' => 4, 'older_exhausted' => false])
        ->and(app(FetchOlderMessages::class)->handle($folder))->toBe(2)
        ->and(storedUids())->toBe([2, 3, 6]);
});

it('marks the folder exhausted once it reached uid 1 and stops asking the server', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 3]);
    storedMessage($folder, 3);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->setMessages([
        remoteMessage(1),
        remoteMessage(3),
    ]);

    app(FetchOlderMessages::class)->handle($folder);
    $fetches = $remote->fetches;

    expect($folder->refresh()->older_exhausted)->toBeTrue()
        ->and(app(FetchOlderMessages::class)->handle($folder))->toBe(0)
        ->and($remote->fetches)->toBe($fetches);
});

it('forgets how far back it fetched when UIDVALIDITY changes', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: [
        'last_uid' => 9,
        'older_exhausted' => true,
        'oldest_uid' => 4,
        'uid_validity' => 1,
    ]);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 2];
    $remote->setMessages([remoteMessage(1)]);

    app(SyncFolderMessages::class)->handle($folder);

    expect($folder->refresh()->only(['oldest_uid', 'older_exhausted']))->toBe(['oldest_uid' => null, 'older_exhausted' => false]);
});

it('keeps going after a short batch and marks the folder exhausted when nothing older comes back', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 9]);
    storedMessage($folder, 9);
    remoteFolder($mailbox, 'INBOX')->setMessages([
        remoteMessage(5),
        remoteMessage(9),
    ]);

    app(FetchOlderMessages::class)->handle($folder);

    expect($folder->refresh()->only(['oldest_uid', 'older_exhausted']))->toBe(['oldest_uid' => 5, 'older_exhausted' => false]);

    app(FetchOlderMessages::class)->handle($folder);

    expect($folder->refresh()->older_exhausted)->toBeTrue();
});

it('leaves a folder whose UIDVALIDITY changed to the sync', function (): void {
    Bus::fake([SyncFolderJob::class]);
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 9, 'uid_validity' => 1]);
    storedMessage($folder, 9);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 2];
    $remote->setMessages([remoteMessage(3)]);

    expect(app(FetchOlderMessages::class)->handle($folder))->toBe(0)
        ->and(storedUids())->toBe([9])
        ->and($folder->refresh()->oldest_uid)->toBeNull();
    Bus::assertDispatched(SyncFolderJob::class, fn (SyncFolderJob $job): bool => $job->folderId === $folder->id);
});

it('works with the stored state of the folder, not a stale copy', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox);
    $stale = $folder->replicate();
    $stale->id = $folder->id;
    $stale->exists = true;
    $folder->update(['older_exhausted' => true]);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    expect(app(FetchOlderMessages::class)->handle($stale))->toBe(0)
        ->and(storedUids())->toBe([]);
});

it('lists a failed job in the last error of the mailbox', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, 'Archive', FolderRole::Custom);

    (new FetchOlderMessagesJob($folder->id))->failed(new RuntimeException('Server went away'));

    expect($mailbox->refresh()->last_error)->toBe('Archive: Server went away');
});
