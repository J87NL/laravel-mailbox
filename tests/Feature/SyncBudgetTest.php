<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
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
    config()->set('mailbox.sync.step_reserve_seconds', 1);
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

    syncWithinBudget($mailbox, 60);

    $inbox = MailboxFolder::query()
        ->where('path', 'INBOX')
        ->sole();

    expect(budgetStoredUids('INBOX'))->toBe([1, 2, 3, 4, 5, 6])
        ->and($inbox->last_uid)->toBe(6)
        ->and($inbox->synced_at)->toBeNull()
        ->and($remote->chunkFetches)->toBe(3)
        ->and($mailbox->refresh()->last_error)->toBeNull();

    syncWithinBudget($mailbox, 60);

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
        ->and(budgetStoredUids('All mail'))->toBe(range(1, 6))
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

    app(SyncFolderMessages::class)->handle($folder, SyncBudget::seconds(100));

    expect(budgetStoredUids('INBOX'))->toBe(range(1, 5))
        ->and($folder->refresh()->synced_at)->not->toBeNull();

    Event::assertNotDispatched(MessageReceived::class);
});

it('defers a folder whose status or search took longer than the budget has left, without holding up the others', function (): void {
    $mailbox = mailbox();
    $remotes = [];

    foreach (['Busy', 'Slow status', 'Slow search', 'Fast'] as $offset => $path) {
        folder($mailbox, $path, FolderRole::Custom, ['last_uid' => 1, 'synced_at' => now()->subMinutes(10 - $offset), 'uid_validity' => 1]);
        $remotes[$path] = budgetRemote($mailbox, $path, [2]);
    }

    $remotes['Busy']->secondsPerChunk = 25;
    $remotes['Slow status']->secondsPerStatus = 18;
    $remotes['Slow search']->secondsPerSearch = 18;

    app(SyncMailbox::class)->handle($mailbox);

    foreach ($remotes as $remote) {
        $remote->addMessage(remoteMessage(3));
    }

    Log::spy();

    syncWithinBudget($mailbox, 40);

    expect(budgetStoredUids('Busy'))->toBe([2, 3])
        ->and(budgetStoredUids('Slow status'))->toBe([2])
        ->and(budgetStoredUids('Slow search'))->toBe([2])
        ->and(budgetStoredUids('Fast'))->toBe([2, 3]);

    Log::shouldHaveReceived('info')
        ->once()
        ->with('Mailbox sync deferred work to the next run', [
            'mailbox_id' => $mailbox->id,
            'deferred' => [
                'Slow status' => 'status',
                'Slow search' => 'fetch',
            ],
        ]);
});

it('lets a step that once took longer than half the budget run when half the budget is left', function (): void {
    $mailbox = mailbox();
    folder($mailbox, 'Archive', FolderRole::Custom, ['last_uid' => 1, 'synced_at' => now()->subMinutes(10), 'uid_validity' => 1]);
    $remote = budgetRemote($mailbox, 'Archive', [2], secondsPerChunk: 60);

    app(SyncMailbox::class)->handle($mailbox);

    $remote->secondsPerChunk = 5;
    $remote->addMessage(remoteMessage(3));

    syncWithinBudget($mailbox, 40);

    expect(budgetStoredUids('Archive'))->toBe([2, 3]);
});

it('finishes a folder whose last chunk used up the budget in the same run', function (): void {
    $mailbox = mailbox();
    budgetRemote($mailbox, 'INBOX', range(1, 3), secondsPerChunk: 30);

    syncWithinBudget($mailbox, 40);

    expect(budgetStoredUids('INBOX'))->toBe([1, 2, 3])
        ->and(MailboxFolder::query()
            ->where('path', 'INBOX')
            ->sole()->synced_at)->not->toBeNull();
});

it('refreshes the flag window in chunks and resumes where the budget stopped it', function (): void {
    config()->set('mailbox.sync.flag_chunk_size', 2);
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 5, 'synced_at' => now()->subMinutes(2), 'uid_validity' => 1]);

    foreach (range(1, 5) as $uid) {
        storedMessage($folder, $uid);
    }

    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 1];
    $remote->secondsPerFetch = 15;
    $remote->setMessages(array_map(fn (int $uid) => remoteMessage($uid, [ImapFlag::Seen->value]), range(1, 5)));

    $seen = fn (): array => $folder->messages()
        ->where('is_seen', true)
        ->orderBy('uid')
        ->pluck('uid')
        ->all();

    app(SyncFolderMessages::class)->handle($folder, SyncBudget::seconds(40));

    expect($seen())->toBe([2, 3, 4, 5])
        ->and($remote->fetches)->toBe(2)
        ->and($folder->refresh()->synced_at->isToday())->toBeTrue();

    app(SyncFolderMessages::class)->handle($folder, SyncBudget::seconds(40));

    expect($seen())->toBe([1, 2, 3, 4, 5])
        ->and($remote->fetches)->toBe(3);

    app(SyncFolderMessages::class)->handle($folder, SyncBudget::seconds(100));

    expect($remote->fetches)->toBe(6);
});

it('never runs longer than the budget plus the measured duration of one step', function (): void {
    $mailbox = mailbox();
    $remotes = [];

    foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $offset => $path) {
        folder($mailbox, $path, FolderRole::Custom, ['last_uid' => 1, 'synced_at' => now()->subMinutes(10 - $offset), 'uid_validity' => 1]);
        $remotes[$path] = budgetRemote($mailbox, $path, [2], secondsPerChunk: 4);
    }

    $remotes['Alpha']->secondsPerStatus = 7;
    $remotes['Beta']->secondsPerSearch = 9;
    $remotes['Gamma']->secondsPerFetch = 10;
    $remotes['Delta']->secondsPerChunk = 6;

    app(SyncMailbox::class)->handle($mailbox);

    foreach ($remotes as $remote) {
        $remote->setMessages(array_map(fn (int $uid) => remoteMessage($uid), range(1, 9)));
    }

    foreach ([12, 25, 33] as $budget) {
        $started = now();

        syncWithinBudget($mailbox, $budget);

        expect(abs($started->diffInSeconds(now())))->toBeLessThanOrEqual($budget + 13);
    }
});

it('stops the run when the IMAP connection times out, without reporting it', function (): void {
    Exceptions::fake();
    $mailbox = mailbox();
    folder($mailbox, 'Alpha', FolderRole::Custom, ['last_uid' => 1, 'synced_at' => now()->subMinutes(10), 'uid_validity' => 1]);
    folder($mailbox, 'Beta', FolderRole::Custom, ['last_uid' => 1, 'synced_at' => now()->subMinutes(5), 'uid_validity' => 1]);
    $remote = test()->connector->remote($mailbox);
    $remote->addFolder(new class('Alpha') extends RecordingFolder
    {
        public function status(): array
        {
            throw new ImapConnectionTimedOutException('Stream timed out, no response');
        }
    });
    budgetRemote($mailbox, 'Beta', [2]);

    syncWithinBudget($mailbox, 40);

    expect($mailbox->refresh()->last_error)->toBe('Alpha: Stream timed out, no response')
        ->and(budgetStoredUids('Beta'))->toBe([]);
    Exceptions::assertNothingReported();
});

it('records a timeout while the folder list is fetched without reporting or failing the job', function (): void {
    Exceptions::fake();
    $mailbox = mailbox();
    test()->connector->imapFailure = new ImapConnectionTimedOutException('Stream timed out, no response');

    app()->call([new SyncMailboxJob($mailbox->id), 'handle']);

    expect($mailbox->refresh()->last_error)->toBe('Stream timed out, no response')
        ->and($mailbox->synced_at)->toBeNull();
    Exceptions::assertNothingReported();
});

it('lets a folder job end without failing on an IMAP timeout and lists it in last_error', function (): void {
    Exceptions::fake();
    $mailbox = mailbox();
    $folder = folder($mailbox);
    test()->connector->imapFailure = new ImapConnectionTimedOutException('Stream timed out, no response');

    app()->call([new SyncFolderJob($folder->id), 'handle']);

    expect($folder->refresh()->synced_at)->toBeNull()
        ->and($mailbox->refresh()->last_error)->toBe('INBOX: Stream timed out, no response');
    Exceptions::assertNothingReported();
});

it('reports a closed IMAP connection and stops the run', function (): void {
    Exceptions::fake();
    $mailbox = mailbox();
    folder($mailbox, 'Alpha', FolderRole::Custom, ['last_uid' => 1, 'synced_at' => now()->subMinutes(10), 'uid_validity' => 1]);
    folder($mailbox, 'Beta', FolderRole::Custom, ['last_uid' => 1, 'synced_at' => now()->subMinutes(5), 'uid_validity' => 1]);
    $remote = test()->connector->remote($mailbox);
    $remote->addFolder(new class('Alpha') extends RecordingFolder
    {
        public function status(): array
        {
            throw new ImapConnectionClosedException('Server closed the connection (EOF)');
        }
    });
    budgetRemote($mailbox, 'Beta', [2]);

    syncWithinBudget($mailbox, 40);

    expect($mailbox->refresh()->last_error)->toBe('Alpha: Server closed the connection (EOF)')
        ->and(budgetStoredUids('Beta'))->toBe([]);
    Exceptions::assertReported(ImapConnectionClosedException::class);
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

it('stops reading from IMAP a few seconds before the job timeout', function (Closure $job): void {
    config()->set('mailbox.sync.job_timeout_seconds', 50);
    $this->freezeTime();
    $mailbox = mailbox();

    app()->call([$job($mailbox), 'handle']);

    expect(test()->connector->readDeadlines[(string) $mailbox->id]?->equalTo(now()->addSeconds(45)))->toBeTrue();
})->with([
    'mailbox job' => fn (Mailbox $mailbox): SyncMailboxJob => new SyncMailboxJob($mailbox->id),
    'folder job' => fn (Mailbox $mailbox): SyncFolderJob => new SyncFolderJob(folder($mailbox)->id),
]);

it('does not limit IMAP reads of a sync without a budget', function (): void {
    $mailbox = mailbox();

    app(SyncMailbox::class)->handle($mailbox);

    expect(test()->connector->readDeadlines)->toBe([(string) $mailbox->id => null]);
});

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
