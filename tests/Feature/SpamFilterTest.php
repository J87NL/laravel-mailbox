<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Bus;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Enums\SpamStatus;
use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Jobs\CheckSpamJob;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Spam\SpamAllowlist;
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
