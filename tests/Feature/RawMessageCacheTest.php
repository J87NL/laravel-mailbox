<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use J87NL\Mailbox\Actions\DeleteMessages;
use J87NL\Mailbox\Actions\FetchMessageBody;
use J87NL\Mailbox\Actions\ForgetMessage;
use J87NL\Mailbox\Actions\MarkMessages;
use J87NL\Mailbox\Actions\MoveMessages;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Jobs\FlagRemoteMessagesJob;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Sync\SyncFolderMessages;
use J87NL\Mailbox\Testing\RecordingFolder;
use Symfony\Component\Mime\Email;

function messageWithImagesAndAttachment(int $uid): FakeMessage
{
    $raw = (new Email)
        ->from('sender@example.org')
        ->to('team@example.com')
        ->subject('Newsletter')
        ->html('<p>Hi</p><img src="cid:logo"><img src="cid:banner">')
        ->embed('LOGO-BYTES', 'logo', 'image/png')
        ->embed('BANNER-BYTES', 'banner', 'image/png')
        ->attach('PDF-BYTES', 'report.pdf', 'application/pdf')
        ->toString();

    return new FakeMessage($uid, [], $raw);
}

/**
 * @return array{0: MailboxMessage, 1: RecordingFolder}
 */
function openedMessage(int $uid = 1): array
{
    $mailbox = mailbox();
    $message = storedMessage(folder($mailbox), $uid)->load('folder');
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->setMessages([messageWithImagesAndAttachment($uid)]);

    return [
        $message,
        $remote,
    ];
}

function rawPath(MailboxMessage $message): string
{
    return sprintf('mailbox/raw/%d/%d.eml', $message->mailbox_id, $message->id);
}

beforeEach(function (): void {
    Bus::fake([SyncFolderJob::class]);
});

it('serves the body, every inline image and the attachments from one fetch', function (): void {
    [$message, $remote] = openedMessage();
    $fetch = app(FetchMessageBody::class);

    $fetch->handle($message);
    $contentIds = collect($message->refresh()->attachments)
        ->pluck('content_id')
        ->filter()
        ->values();
    $images = $contentIds->map(fn (string $contentId): ?string => $fetch->inlineAttachment($message, $contentId)?->contents());
    $pdfIndex = collect($message->attachments)->search(fn (array $attachment): bool => $attachment['filename'] === 'report.pdf');

    expect($remote->messageFetches)->toBe(1)
        ->and($message->body_html)->toContain('<p>Hi</p>')
        ->and($images->all())->toBe([
            'LOGO-BYTES',
            'BANNER-BYTES',
        ])
        ->and($fetch->attachment($message, $pdfIndex)?->contents())->toBe('PDF-BYTES')
        ->and($fetch->inlineAttachments($message))->toHaveCount(2)
        ->and($remote->messageFetches)->toBe(1);
});

it('fetches again once the raw message expired', function (): void {
    config()->set('mailbox.cache.raw_hours', 24);
    [$message, $remote] = openedMessage();
    $fetch = app(FetchMessageBody::class);

    $fetch->attachment($message, 0);
    $this->travel(25)->hours();
    $fetch->attachment($message, 0);

    expect($remote->messageFetches)->toBe(2);
});

it('fetches every time when the raw cache is switched off', function (?int $hours): void {
    config()->set('mailbox.cache.raw_hours', $hours);
    [$message, $remote] = openedMessage();
    $fetch = app(FetchMessageBody::class);

    $fetch->attachment($message, 0);
    $fetch->attachment($message, 0);

    expect($remote->messageFetches)->toBe(2)
        ->and(Storage::disk('local')->exists(rawPath($message)))->toBeFalse();
})->with([
    'null' => [null],
    'zero' => [0],
]);

it('stores the raw message on the configured disk', function (): void {
    Storage::fake('mail');
    config()->set('mailbox.cache.raw_disk', 'mail');
    [$message] = openedMessage();

    app(FetchMessageBody::class)->handle($message);

    expect(Storage::disk('mail')->exists(rawPath($message)))->toBeTrue()
        ->and(Storage::disk('local')->exists(rawPath($message)))->toBeFalse();
});

it('forgets the raw message when the message is moved, deleted or forgotten', function (Closure $remove): void {
    [$message] = openedMessage();
    app(FetchMessageBody::class)->handle($message);

    expect(Storage::disk('local')->exists(rawPath($message)))->toBeTrue();

    $remove($message);

    expect(Storage::disk('local')->exists(rawPath($message)))->toBeFalse();
})->with([
    'moved' => [fn (MailboxMessage $message) => app(MoveMessages::class)->handle(collect([$message]), folder($message->mailbox, 'Projects', FolderRole::Custom), null)],
    'deleted from trash' => [function (MailboxMessage $message): void {
        $message->folder->update(['role' => FolderRole::Trash]);
        app(DeleteMessages::class)->handle($message->mailbox, collect([$message->refresh()]), null);
    }],
    'forgotten' => [fn (MailboxMessage $message) => app(ForgetMessage::class)->handle($message)],
    'mailbox deleted' => [fn (MailboxMessage $message) => $message->mailbox->delete()],
]);

it('prunes expired raw messages and those of pruned bodies', function (): void {
    config()->set('mailbox.cache.body_days', 30);
    config()->set('mailbox.cache.raw_hours', 24);
    [$message] = openedMessage();
    $fetch = app(FetchMessageBody::class);
    $fetch->handle($message);
    $old = storedMessage($message->folder, 2, [
        'body_fetched_at' => now()->subDays(40),
        'body_html' => '<p>old</p>',
    ])->load('folder');
    remoteFolder($message->mailbox, 'INBOX')->setMessages([
        messageWithImagesAndAttachment(1),
        messageWithImagesAndAttachment(2),
    ]);
    $fetch->attachment($old, 0);

    $this->artisan('mailbox:prune-bodies')->assertSuccessful();

    expect(Storage::disk('local')->exists(rawPath($message)))->toBeTrue()
        ->and(Storage::disk('local')->exists(rawPath($old)))->toBeFalse();

    $this->travel(25)->hours();
    $this->artisan('mailbox:prune-bodies')->assertSuccessful();

    expect(Storage::disk('local')->exists(rawPath($message)))->toBeFalse();
});

it('marks messages read right away and sets the seen flag on the server after the response', function (): void {
    Bus::fake([FlagRemoteMessagesJob::class]);
    $mailbox = mailbox();
    $inbox = folder($mailbox, attributes: ['unseen_count' => 2]);
    $message = storedMessage($inbox, 7);
    $actor = user();

    app(MarkMessages::class)->readInBackground(collect([$message]), $actor);

    expect($message->refresh()->is_seen)->toBeTrue()
        ->and($inbox->refresh()->unseen_count)->toBe(1)
        ->and(MailboxAuditEntry::query()->sole()->action)->toBe(AuditAction::MarkedRead)
        ->and(remoteFolder($mailbox, 'INBOX')->operations)->toBe([]);

    Bus::assertDispatchedAfterResponse(FlagRemoteMessagesJob::class, fn (FlagRemoteMessagesJob $job): bool => $job->folderId === $inbox->id
        && $job->uids === [7]
        && $job->flag === ImapFlag::Seen
        && $job->operation === '+');
});

it('sets the flag on the server when the job runs', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->setMessages([remoteMessage(7)]);

    (new FlagRemoteMessagesJob($inbox->id, [7], ImapFlag::Seen, '+'))->handle($this->connector);

    expect($remote->operations)->toBe([
        [
            'flag' => '\\Seen',
            'operation' => '+',
            'type' => 'flag',
            'uids' => [7],
        ],
    ])
        ->and($remote->message(7)?->flags())->toContain('\\Seen');
});

it('reports a failing flag update instead of throwing after the response', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    $this->connector->failImap();
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reported): void {
        $reported[] = $exception->getMessage();
    });

    (new FlagRemoteMessagesJob($inbox->id, [7], ImapFlag::Seen, '+'))->handle($this->connector);

    expect($reported)->toBe(['Connection refused']);
});

it('forgets the raw messages of messages the sync finds gone from the server', function (): void {
    [$message, $remote] = openedMessage();
    app(FetchMessageBody::class)->handle($message);
    $remote->setMessages([]);

    app(SyncFolderMessages::class)->handle($message->folder);

    expect(MailboxMessage::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists(rawPath($message)))->toBeFalse();
});

it('fetches again when the raw message file is gone', function (): void {
    [$message, $remote] = openedMessage();
    $fetch = app(FetchMessageBody::class);
    $fetch->handle($message);
    Storage::disk('local')->delete(rawPath($message));

    expect($fetch->attachment($message, 0))->not->toBeNull()
        ->and($remote->messageFetches)->toBe(2)
        ->and(Storage::disk('local')->allFiles('mailbox/raw'))->toBe([rawPath($message)]);
});

it('forgets the raw messages of a folder whose UIDVALIDITY changed', function (): void {
    [$message, $remote] = openedMessage();
    $message->folder->update(['uid_validity' => 1]);
    app(FetchMessageBody::class)->handle($message);
    $remote->status = ['UIDVALIDITY' => 2];

    app(SyncFolderMessages::class)->handle($message->folder->refresh());

    expect(Storage::disk('local')->exists(rawPath($message)))->toBeFalse();
});
