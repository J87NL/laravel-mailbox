<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Jobs\SyncMailboxJob;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Sync\SyncBudget;
use J87NL\Mailbox\Sync\SyncFolderMessages;
use J87NL\Mailbox\Sync\SyncMailbox;
use J87NL\Mailbox\Testing\RecordingFolder;

beforeEach(function (): void {
    config()->set('mailbox.sync.chunk_size', 2);
});

function syncWithinBudget(Mailbox $mailbox, int $seconds): void
{
    app(SyncMailbox::class)->handle($mailbox, SyncBudget::seconds($seconds));
}

/**
 * @param  array<int, int>  $uids
 */
function budgetRemote(Mailbox $mailbox, string $path, array $uids, int $secondsPerChunk = 0): RecordingFolder
{
    $remote = remoteFolder($mailbox, $path);
    $remote->status = ['UIDVALIDITY' => 1];
    $remote->secondsPerChunk = $secondsPerChunk;
    $remote->setMessages(array_map(fn (int $uid) => remoteMessage($uid), $uids));

    return $remote;
}

function budgetStoredUids(string $path): array
{
    return MailboxFolder::query()
        ->where('path', $path)
        ->sole()
        ->messages()
        ->orderBy('uid')
        ->pluck('uid')
        ->all();
}

it('stops when the budget is used, keeps its progress and finishes on the next run', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $remote = budgetRemote($mailbox, 'INBOX', range(1, 7), secondsPerChunk: 15);

    syncWithinBudget($mailbox, 40);

    $inbox = MailboxFolder::query()
        ->where('path', 'INBOX')
        ->sole();

    expect(budgetStoredUids('INBOX'))->toBe([1, 2, 3, 4, 5, 6])
        ->and($inbox->last_uid)->toBe(6)
        ->and($inbox->synced_at)->toBeNull()
        ->and($remote->chunkFetches)->toBe(3)
        ->and($mailbox->refresh()->last_error)->toBeNull();

    syncWithinBudget($mailbox, 40);

    expect(budgetStoredUids('INBOX'))->toBe(range(1, 7))
        ->and($inbox->refresh()->synced_at)->not->toBeNull()
        ->and($remote->chunkFetches)->toBe(4);

    Event::assertNotDispatched(MessageReceived::class);
});

it('syncs new mail in the inbox and other synced folders before a folder that is still backfilling', function (): void {
    $mailbox = mailbox();
    folder($mailbox, 'INBOX', attributes: ['last_uid' => 10, 'synced_at' => now()->subMinutes(2), 'uid_validity' => 1]);
    folder($mailbox, 'All mail', FolderRole::Archive);
    folder($mailbox, 'Work', FolderRole::Custom, ['last_uid' => 3, 'synced_at' => now()->subMinutes(2), 'uid_validity' => 1]);
    $inbox = budgetRemote($mailbox, 'INBOX', [11]);
    budgetRemote($mailbox, 'All mail', range(1, 20), secondsPerChunk: 30);
    budgetRemote($mailbox, 'Work', [4]);

    syncWithinBudget($mailbox, 40);

    expect(budgetStoredUids('INBOX'))->toBe([11])
        ->and(budgetStoredUids('Work'))->toBe([4])
        ->and(budgetStoredUids('All mail'))->toBe([1, 2, 3, 4]);

    $inbox->addMessage(remoteMessage(12));

    syncWithinBudget($mailbox, 40);

    expect(budgetStoredUids('INBOX'))->toBe([11, 12])
        ->and(budgetStoredUids('All mail'))->toBe(range(1, 8))
        ->and(MailboxFolder::query()
            ->where('path', 'All mail')
            ->sole()->synced_at)->toBeNull();
});

it('starts the next run with the synced folders the previous run did not reach', function (): void {
    $mailbox = mailbox();
    $remotes = [];

    foreach (['Alpha', 'Beta', 'Gamma'] as $offset => $path) {
        folder($mailbox, $path, FolderRole::Custom, ['last_uid' => 1, 'synced_at' => now()->subMinutes(10 - $offset), 'uid_validity' => 1]);
        $remotes[$path] = budgetRemote($mailbox, $path, [2], secondsPerChunk: 15);
    }

    syncWithinBudget($mailbox, 20);

    expect(budgetStoredUids('Alpha'))->toBe([2])
        ->and(budgetStoredUids('Beta'))->toBe([2])
        ->and(budgetStoredUids('Gamma'))->toBe([]);

    $remotes['Alpha']->addMessage(remoteMessage(3));

    syncWithinBudget($mailbox, 10);

    expect(budgetStoredUids('Gamma'))->toBe([2])
        ->and(budgetStoredUids('Alpha'))->toBe([2]);

    syncWithinBudget($mailbox, 20);

    expect(budgetStoredUids('Alpha'))->toBe([2, 3]);
});

it('does not announce mail of a UIDVALIDITY reset that takes more than one run', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 90, 'synced_at' => now()->subMinutes(2), 'uid_validity' => 1]);
    storedMessage($folder, 90);
    $remote = budgetRemote($mailbox, 'INBOX', range(1, 5), secondsPerChunk: 30);
    $remote->status = ['UIDVALIDITY' => 2];

    app(SyncFolderMessages::class)->handle($folder, SyncBudget::seconds(40));

    expect(budgetStoredUids('INBOX'))->toBe([1, 2, 3, 4])
        ->and($folder->refresh()->synced_at)->toBeNull();

    app(SyncFolderMessages::class)->handle($folder, SyncBudget::seconds(40));

    expect(budgetStoredUids('INBOX'))->toBe(range(1, 5))
        ->and($folder->refresh()->synced_at)->not->toBeNull();

    Event::assertNotDispatched(MessageReceived::class);
});

it('gives the sync jobs a timeout between the time budget and the queue retry_after', function (string $job): void {
    $instance = new $job(1);

    expect($instance->timeout)->toBeGreaterThan((int) config('mailbox.sync.time_budget_seconds'))
        ->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($instance->failOnTimeout)->toBeTrue();
})->with([
    SyncMailboxJob::class,
    SyncFolderJob::class,
]);

test('a time budget works in a host that uses immutable dates', function (): void {
    Date::use(CarbonImmutable::class);

    try {
        $budget = SyncBudget::seconds(40);

        expect($budget->isLimited())->toBeTrue()
            ->and($budget->exhausted())->toBeFalse();
    } finally {
        Date::useDefault();
    }
});
