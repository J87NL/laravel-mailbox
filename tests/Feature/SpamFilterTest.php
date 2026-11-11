<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use J87NL\Mailbox\Actions\MarkJunk;
use J87NL\Mailbox\Actions\RecordAudit;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Enums\SpamAllowlistSource;
use J87NL\Mailbox\Enums\SpamStatus;
use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Jobs\CheckSpamJob;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Models\MailboxSpamAllowlistEntry;
use J87NL\Mailbox\Spam\SpamAllowlist;
use J87NL\Mailbox\Sync\SyncFolderMessages;
use J87NL\Mailbox\Testing\FakeSpamClassifier;

function spamMailbox(string $mode): Mailbox
{
    $mailbox = mailbox(['spam_filter_mode' => $mode]);
    folder($mailbox, 'Spam', FolderRole::Junk);
    remoteFolder($mailbox, 'Spam');

    return $mailbox;
}

function receivedMessage(Mailbox $mailbox, string $from = 'sender@example.org'): MailboxMessage
{
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    return storedMessage($mailbox->folderWithRole(FolderRole::Inbox) ?? folder($mailbox), 1, ['from_email' => $from])
        ->load('folder.mailbox');
}

function checkSpam(MailboxMessage $message): ?MailboxMessage
{
    app()->call([new CheckSpamJob($message->id), 'handle']);

    return MailboxMessage::query()->find($message->id);
}

beforeEach(function (): void {
    Bus::fake([SyncFolderJob::class]);
});

it('queues no spam check and leaves the message alone when the filter is off', function (): void {
    Bus::fake([CheckSpamJob::class, SyncFolderJob::class]);
    $classifier = FakeSpamClassifier::install()->score(1.0);
    $message = receivedMessage(spamMailbox('off'));

    MessageReceived::dispatch($message);
    $checked = checkSpam($message);

    Bus::assertNotDispatched(CheckSpamJob::class);
    expect($classifier->classified)->toBe([])
        ->and($checked->spam_checked_at)->toBeNull();
});

it('queues a spam check for new mail when the filter is on', function (): void {
    Bus::fake([CheckSpamJob::class, SyncFolderJob::class]);
    $message = receivedMessage(spamMailbox('measure'));

    MessageReceived::dispatch($message);

    Bus::assertDispatched(CheckSpamJob::class, fn (CheckSpamJob $job): bool => $job->messageId === $message->id);
});

it('skips the classifier for an allowlisted sender', function (): void {
    $classifier = FakeSpamClassifier::install()->score(1.0);
    $mailbox = spamMailbox('auto');
    app(SpamAllowlist::class)->add(null, 'example.org');

    $checked = checkSpam(receivedMessage($mailbox));

    expect($classifier->classified)->toBe([])
        ->and($checked->spam_status)->toBeNull()
        ->and($checked->spam_score)->toBeNull()
        ->and($checked->spam_checked_at)->not->toBeNull();
});

it('never moves a message without a verdict or when the classifier fails', function (?Throwable $failure): void {
    $classifier = FakeSpamClassifier::install();
    $classifier->failure = $failure;
    $this->mock(ExceptionHandler::class)->shouldReceive('report')->times($failure ? 1 : 0);
    $mailbox = spamMailbox('auto');

    $checked = checkSpam(receivedMessage($mailbox));

    expect($classifier->classified)->toHaveCount(1)
        ->and($checked->spam_status)->toBeNull()
        ->and($checked->spam_score)->toBeNull()
        ->and($checked->spam_checked_at)->not->toBeNull()
        ->and(remoteFolder($mailbox, 'INBOX')->operations)->toBe([]);
})->with([
    'no verdict' => [null],
    'failure' => [new RuntimeException('Classifier down')],
]);

it('stores the score and sets the status by mode and threshold', function (string $mode, float $score, ?SpamStatus $status): void {
    FakeSpamClassifier::install()->score($score, 'Looks like bulk mail');
    $mailbox = spamMailbox($mode);

    $checked = checkSpam(receivedMessage($mailbox));

    expect($checked->spam_score)->toBe($score)
        ->and($checked->spam_reason)->toBe('Looks like bulk mail')
        ->and($checked->spam_status)->toBe($status)
        ->and(remoteFolder($mailbox, 'INBOX')->operations)->toBe([]);
})->with([
    'measure never suggests' => ['measure', 0.99, null],
    'suggest below the threshold' => ['suggest', 0.79, null],
    'suggest from the threshold' => ['suggest', 0.8, SpamStatus::Suggested],
    'suggest never moves' => ['suggest', 0.99, SpamStatus::Suggested],
    'auto between the thresholds' => ['auto', 0.94, SpamStatus::Suggested],
]);

it('moves clear spam to the junk folder in auto mode with a spam_moved audit entry', function (): void {
    FakeSpamClassifier::install()->score(0.95, 'Phishing');
    $mailbox = spamMailbox('auto');

    $checked = checkSpam(receivedMessage($mailbox));

    expect($checked)->toBeNull()
        ->and(collect(remoteFolder($mailbox, 'INBOX')->operations)->last())->toBe([
            'target' => 'Spam',
            'type' => 'move',
            'uids' => [1],
        ])
        ->and(MailboxAuditEntry::query()
            ->sole()
            ->only([
                'action',
                'meta',
                'target_path',
                'user_id',
            ]))->toBe([
                'action' => AuditAction::SpamMoved,
                'meta' => [
                    'reason' => 'Phishing',
                    'score' => 0.95,
                ],
                'target_path' => 'Spam',
                'user_id' => null,
            ]);
});

it('checks a message only once', function (): void {
    $classifier = FakeSpamClassifier::install()->score(0.5);
    $message = receivedMessage(spamMailbox('measure'));

    checkSpam($message);
    checkSpam($message);

    expect($classifier->classified)->toHaveCount(1);
});

it('adds the sender to the allowlist and releases the message when a user marks it not junk', function (): void {
    $mailbox = spamMailbox('suggest');
    $junk = $mailbox->folderWithRole(FolderRole::Junk);
    folder($mailbox);
    remoteFolder($mailbox, 'Spam')->setMessages([remoteMessage(5)]);
    $suggested = receivedMessage($mailbox, 'Friend@Example.org');
    $suggested->update(['spam_status' => SpamStatus::Suggested]);
    $inJunk = storedMessage($junk, 5, ['from_email' => 'other@example.net'])->load('folder.mailbox');

    app(MarkJunk::class)->notJunk($mailbox, collect([$suggested, $inJunk]), user());

    expect(MailboxSpamAllowlistEntry::query()
        ->orderBy('pattern')
        ->get()
        ->map(fn (MailboxSpamAllowlistEntry $entry): array => [$entry->mailbox_id, $entry->pattern, $entry->source])
        ->all())->toBe([
            [$mailbox->id, 'friend@example.org', SpamAllowlistSource::NotJunk],
            [$mailbox->id, 'other@example.net', SpamAllowlistSource::NotJunk],
        ])
        ->and($suggested->refresh()->spam_status)->toBe(SpamStatus::Released)
        ->and(MailboxMessage::query()->find($inJunk->id))->toBeNull();
});

it('learns nothing from a system move or a mailbox with the filter off', function (string $mode, bool $withUser): void {
    $mailbox = spamMailbox($mode);
    $message = receivedMessage($mailbox);
    $message->update(['spam_status' => SpamStatus::Suggested]);

    app(MarkJunk::class)->notJunk($mailbox, collect([$message]), $withUser ? user() : null);

    expect(MailboxSpamAllowlistEntry::query()->count())->toBe(0)
        ->and($message->refresh()->spam_status)->toBe(SpamStatus::Suggested);
})->with([
    'system' => ['auto', false],
    'filter off' => ['off', true],
]);

it('clears the suggestion when a user marks a message junk', function (): void {
    $mailbox = spamMailbox('suggest');
    $junk = $mailbox->folderWithRole(FolderRole::Junk);
    remoteFolder($mailbox, 'Spam')->setMessages([remoteMessage(5)]);
    $message = storedMessage($junk, 5, ['spam_status' => SpamStatus::Suggested])->load('folder.mailbox');

    app(MarkJunk::class)->junk($mailbox, collect([$message]), user());

    expect($message->refresh()->spam_status)->toBeNull();
});

function syncedJunkFolder(Mailbox $mailbox): MailboxFolder
{
    $junk = $mailbox->folderWithRole(FolderRole::Junk);
    $junk->update(['last_uid' => 1, 'synced_at' => now(), 'uid_validity' => 1]);
    remoteFolder($mailbox, 'Spam')->status = ['UIDVALIDITY' => 1];
    remoteFolder($mailbox, 'Spam')->setMessages([remoteMessage(2)]);

    return $junk;
}

it('moves allowlisted mail out of the junk folder during the sync', function (): void {
    $mailbox = spamMailbox('measure');
    folder($mailbox);
    app(SpamAllowlist::class)->add($mailbox, 'Sender@example.org');

    app(SyncFolderMessages::class)->handle(syncedJunkFolder($mailbox));

    expect(collect(remoteFolder($mailbox, 'Spam')->operations)->last())->toBe([
        'target' => 'INBOX',
        'type' => 'move',
        'uids' => [2],
    ])
        ->and(MailboxAuditEntry::query()->sole()->only(['action', 'user_id']))->toBe([
            'action' => AuditAction::MarkedNotJunk,
            'user_id' => null,
        ]);
});

it('leaves junk alone when the filter is off, the sender is unknown or a user put it there', function (string $mode, bool $allowlisted, ?AuditAction $history): void {
    $mailbox = spamMailbox($mode);
    folder($mailbox);
    $junk = syncedJunkFolder($mailbox);

    if ($allowlisted) {
        app(SpamAllowlist::class)->add($mailbox, 'sender@example.org');
    }

    if ($history) {
        app(RecordAudit::class)->forMailbox($mailbox, $history, user(), 'message-2@example.org');
    }

    app(SyncFolderMessages::class)->handle($junk);

    expect(remoteFolder($mailbox, 'Spam')->operations)->toBe([])
        ->and(MailboxMessage::query()->where('mailbox_folder_id', $junk->id)->count())->toBe(1);
})->with([
    'filter off' => ['off', true, null],
    'unknown sender' => ['auto', false, null],
    'marked junk by a user' => ['auto', true, AuditAction::MarkedJunk],
    'moved there by a user' => ['auto', true, AuditAction::Moved],
]);

it('shows the verdict on mail the filter moved to the junk folder', function (): void {
    $mailbox = spamMailbox('auto');
    $junk = syncedJunkFolder($mailbox);
    app(RecordAudit::class)->forMailbox($mailbox, AuditAction::SpamMoved, null, 'message-2@example.org', meta: [
        'reason' => 'Phishing',
        'score' => 0.97,
    ]);

    app(SyncFolderMessages::class)->handle($junk);

    expect(MailboxMessage::query()->where('mailbox_folder_id', $junk->id)->sole()->only(['spam_reason', 'spam_score', 'spam_status']))->toBe([
        'spam_reason' => 'Phishing',
        'spam_score' => 0.97,
        'spam_status' => SpamStatus::Moved,
    ]);
});

it('announces mail that comes back from the junk folder once, and keeps a released verdict', function (?int $userId, ?SpamStatus $status): void {
    Event::fake([MessageReceived::class]);
    $mailbox = spamMailbox('suggest');
    $inbox = folder($mailbox, attributes: ['last_uid' => 1, 'synced_at' => now(), 'uid_validity' => 1]);
    remoteFolder($mailbox, 'INBOX')->status = ['UIDVALIDITY' => 1];
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(2)]);
    $user = $userId ? user() : null;
    app(RecordAudit::class)->forMailbox($mailbox, AuditAction::MarkedNotJunk, $user, 'message-2@example.org');

    app(SyncFolderMessages::class)->handle($inbox);
    app(SyncFolderMessages::class)->handle($inbox->refresh());

    Event::assertDispatchedTimes(MessageReceived::class, 1);
    expect(MailboxMessage::query()->sole()->spam_status)->toBe($status)
        ->and(MailboxAuditEntry::query()->where('action', AuditAction::Received)->count())->toBe(1);
})->with([
    'released by the allowlist' => [null, null],
    'released by a user' => [1, SpamStatus::Released],
]);

it('only suggests clear spam in auto mode once a user has read, flagged or answered it', function (string $flag): void {
    FakeSpamClassifier::install()->score(0.99);
    $mailbox = spamMailbox('auto');
    $message = receivedMessage($mailbox);
    $message->update([$flag => true]);

    $checked = checkSpam($message);

    expect($checked->spam_status)->toBe(SpamStatus::Suggested)
        ->and(remoteFolder($mailbox, 'INBOX')->operations)->toBe([]);
})->with([
    'is_seen',
    'is_flagged',
    'is_answered',
]);

it('cuts a long reason to the column length', function (): void {
    FakeSpamClassifier::install()->score(0.5, str_repeat('a', 400));

    $checked = checkSpam(receivedMessage(spamMailbox('measure')));

    expect(mb_strlen((string) $checked->spam_reason))->toBe(255);
});
