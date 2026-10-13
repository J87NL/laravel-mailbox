<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Support\Str as Utf7;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use J87NL\Mailbox\Actions\CreateFolder;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Sync\SyncFolderMessages;
use J87NL\Mailbox\Sync\SyncFolders;
use J87NL\Mailbox\Sync\SyncMailbox;
use J87NL\Mailbox\Testing\RecordingFolder;

function syncFolder(MailboxFolder $folder): MailboxFolder
{
    app(SyncFolderMessages::class)->handle($folder);

    return $folder->refresh();
}

it('mirrors the remote folder list with roles and drops vanished folders', function (): void {
    $mailbox = mailbox();
    $remote = test()->connector->remote($mailbox);
    $remote->addFolder(new RecordingFolder('Sent Items', ['\\Sent']));
    $remote->addFolder(new RecordingFolder('Old', ['\\Noselect']));
    folder($mailbox, 'Vanished', FolderRole::Custom);

    app(SyncFolders::class)->handle($mailbox);

    expect(MailboxFolder::query()
        ->orderBy('path')->get(['path', 'role', 'selectable'])->toArray())->toBe([
            ['path' => 'INBOX', 'role' => 'inbox', 'selectable' => true],
            ['path' => 'Old', 'role' => 'custom', 'selectable' => false],
            ['path' => 'Sent Items', 'role' => 'sent', 'selectable' => true],
        ]);
});

it('stores new messages with headers and flags without announcing the initial sync', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $folder = folder($mailbox);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 7, 'UNSEEN' => 1];
    $remote->setMessages([
        remoteMessage(3, [ImapFlag::Seen->value, ImapFlag::Flagged->value]),
        remoteMessage(5),
    ]);

    $folder = syncFolder($folder);

    $stored = MailboxMessage::query()
        ->orderBy('uid')->get();

    expect($stored->pluck('uid')->all())->toBe([3, 5])
        ->and($stored[0]->only(['subject', 'from_email', 'from_name', 'message_id', 'is_seen', 'is_flagged']))->toBe([
            'subject' => 'Message 3',
            'from_email' => 'sender@example.org',
            'from_name' => 'Sender Name',
            'message_id' => 'message-3@example.org',
            'is_seen' => true,
            'is_flagged' => true,
        ])
        ->and($stored[0]->to)->toBe([['email' => 'team@example.com', 'name' => '']])
        ->and($folder->only(['last_uid', 'uid_validity', 'unseen_count']))->toBe(['last_uid' => 5, 'uid_validity' => 7, 'unseen_count' => 1])
        ->and($folder->synced_at)->not->toBeNull()
        ->and(MailboxAuditEntry::query()
            ->where('action', AuditAction::Received)
            ->count())->toBe(2);

    Event::assertNotDispatched(MessageReceived::class);
});

it('only stores messages above the last known uid on the next run', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 4, 'synced_at' => now(), 'uid_validity' => 1]);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 1];
    storedMessage($folder, 4);
    $remote->setMessages([remoteMessage(4), remoteMessage(6)]);

    syncFolder($folder);

    expect(MailboxMessage::query()
        ->orderBy('uid')->pluck('uid')->all())->toBe([4, 6]);
    Event::assertDispatchedTimes(MessageReceived::class, 1);
});

it('drops the folder cache when UIDVALIDITY changes', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 90, 'uid_validity' => 1]);
    storedMessage($folder, 90);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 2];
    $remote->setMessages([remoteMessage(1)]);

    Event::fake([MessageReceived::class]);

    $folder = syncFolder($folder);

    Event::assertNotDispatched(MessageReceived::class);

    expect(MailboxMessage::query()
        ->pluck('uid')->all())->toBe([1])
        ->and($folder->only(['last_uid', 'uid_validity']))->toBe(['last_uid' => 1, 'uid_validity' => 2]);
});

it('deletes rows that vanished remotely and refreshes changed flags', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 3, 'uid_validity' => 1]);
    storedMessage($folder, 1, ['is_seen' => false]);
    storedMessage($folder, 2);
    storedMessage($folder, 3, ['is_seen' => true, 'is_flagged' => true]);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 1];
    $remote->setMessages([
        remoteMessage(1, [ImapFlag::Seen->value, ImapFlag::Answered->value]),
        remoteMessage(3),
    ]);

    syncFolder($folder);

    expect(MailboxMessage::query()
        ->orderBy('uid')->get(['uid', 'is_seen', 'is_flagged', 'is_answered'])->toArray())->toBe([
            ['uid' => 1, 'is_seen' => true, 'is_flagged' => false, 'is_answered' => true],
            ['uid' => 3, 'is_seen' => false, 'is_flagged' => false, 'is_answered' => false],
        ]);
});

it('treats messages flagged deleted as gone', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 1, 'uid_validity' => 1]);
    storedMessage($folder, 1);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 1];
    $remote->setMessages([
        remoteMessage(1, [ImapFlag::Deleted->value]),
        remoteMessage(2, [ImapFlag::Deleted->value]),
    ]);

    $folder = syncFolder($folder);

    expect(MailboxMessage::query()
        ->count())->toBe(0)
        ->and($folder->last_uid)->toBe(2);
});

it('truncates oversized headers to the column length', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox);
    $long = Str::repeat('a', 300);
    $email = email(Str::repeat('Long subject ', 40))
        ->from(sprintf('%s <%s@example.org>', Str::repeat('Name ', 80), Str::repeat('b', 60)));
    $email->getHeaders()->addIdHeader('Message-ID', sprintf('%s@example.org', $long));
    $email->getHeaders()->addIdHeader('In-Reply-To', sprintf('%s@example.org', $long));
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1, email: $email)]);

    syncFolder($folder);

    $stored = MailboxMessage::query()
        ->sole();

    expect(mb_strlen((string) $stored->subject))->toBeLessThanOrEqual(255)
        ->and(mb_strlen((string) $stored->from_name))->toBeLessThanOrEqual(255)
        ->and(mb_strlen((string) $stored->message_id))->toBe(255)
        ->and(mb_strlen((string) $stored->in_reply_to))->toBe(255);
});

it('records the error on the mailbox when a sync fails', function (): void {
    $mailbox = mailbox();
    test()->connector->failImap('Login failed');

    expect(fn () => app(SyncMailbox::class)->handle($mailbox))->toThrow(RuntimeException::class);
    expect($mailbox->refresh()->last_error)->toBe('Login failed');
});

it('syncs folders with non-ASCII names and keeps going when one folder fails', function (): void {
    $mailbox = mailbox();
    $remote = test()->connector->remote($mailbox);
    $remote->addFolder(new RecordingFolder(Utf7::toImapUtf7('Études & co')));
    $remote->addFolder(new class('Broken') extends RecordingFolder
    {
        public function status(): array
        {
            throw new RuntimeException('Mailbox is locked');
        }
    });
    $remote->addFolder(new RecordingFolder('Zzz'));
    remoteFolder($mailbox, 'Études & co')->setMessages([remoteMessage(1)]);
    remoteFolder($mailbox, 'Zzz')->setMessages([remoteMessage(2)]);

    app(SyncMailbox::class)->handle($mailbox);

    $studies = MailboxFolder::query()
        ->where('name', 'Études & co')->sole();

    expect($studies->path)->toBe('&AMk-tudes &- co')
        ->and($studies->messages()->pluck('uid')->all())->toBe([1])
        ->and(MailboxFolder::query()
            ->where('path', 'Zzz')->sole()->messages()->count())->toBe(1)
        ->and($mailbox->refresh()->last_error)->toBe('Broken: Mailbox is locked')
        ->and($mailbox->synced_at)->not->toBeNull();
});

it('creates a subfolder below a non-ASCII parent', function (): void {
    $mailbox = mailbox();
    test()->connector->remote($mailbox)->addFolder(new RecordingFolder(Utf7::toImapUtf7('Études')));
    $parent = folder($mailbox, Utf7::toImapUtf7('Études'), FolderRole::Custom);

    $created = app(CreateFolder::class)->handle($mailbox, 'Été', $parent, null);

    expect($created->path)->toBe(Utf7::toImapUtf7('Études/Été'))
        ->and($created->name)->toBe('Été');
});

it('announces a new message once and records it as received', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 1, 'synced_at' => now(), 'uid_validity' => 1]);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 1];
    $remote->setMessages([remoteMessage(2)]);

    syncFolder($folder);
    syncFolder($folder);

    Event::assertDispatchedTimes(MessageReceived::class, 1);

    expect(MailboxAuditEntry::query()
        ->sole()->only(['action', 'message_id']))->toBe([
            'action' => AuditAction::Received,
            'message_id' => 'message-2@example.org',
        ]);
});

it('does not announce copies of messages the mailbox already knows', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $inbox = folder($mailbox, attributes: ['last_uid' => 1, 'synced_at' => now(), 'uid_validity' => 1]);
    $archive = folder($mailbox, 'Archive', FolderRole::Archive, ['last_uid' => 9, 'synced_at' => now(), 'uid_validity' => 1]);
    storedMessage($inbox, 1, ['message_id' => 'message-10@example.org']);
    MailboxAuditEntry::query()
        ->create([
            'action' => AuditAction::Sent,
            'mailbox_id' => $mailbox->id,
            'message_id' => 'message-11@example.org',
        ]);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);
    remoteFolder($mailbox, 'Archive')->status = ['UIDVALIDITY' => 1];
    remoteFolder($mailbox, 'Archive')->setMessages([remoteMessage(10), remoteMessage(11), remoteMessage(12)]);

    syncFolder($archive);

    Event::assertDispatchedTimes(MessageReceived::class, 1);
    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event): bool => $event->message->uid === 12);
});

it('never announces messages in sent, drafts, junk or trash', function (FolderRole $role): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $folder = folder($mailbox, 'Special', $role, ['last_uid' => 1, 'uid_validity' => 1]);
    remoteFolder($mailbox, 'Special')->status = ['UIDVALIDITY' => 1];
    remoteFolder($mailbox, 'Special')->setMessages([remoteMessage(2)]);

    syncFolder($folder);

    expect(MailboxMessage::query()
        ->count())->toBe(1);
    Event::assertNotDispatched(MessageReceived::class);
})->with([
    FolderRole::Sent,
    FolderRole::Drafts,
    FolderRole::Junk,
    FolderRole::Trash,
]);

it('stores reply-to addresses and the date in the app timezone', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox);
    $email = email()
        ->replyTo('Support <support@example.org>')
        ->date(new DateTimeImmutable('2026-10-13 14:00:00', new DateTimeZone('+02:00')));
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1, email: $email)]);

    syncFolder($folder);

    $stored = MailboxMessage::query()
        ->sole();

    expect($stored->reply_to)->toBe([['email' => 'support@example.org', 'name' => 'Support']])
        ->and($stored->getRawOriginal('sent_at'))->toBe('2026-10-13 12:00:00');
});

it('skips folder sync jobs of inactive mailboxes', function (): void {
    $folder = folder(mailbox(['active' => false]));
    test()->connector->failImap('Should not connect');

    app()->call([new SyncFolderJob($folder->id), 'handle']);

    expect($folder->refresh()->synced_at)->toBeNull();
});

it('announces the first message of a folder that was empty at the initial sync', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $folder = folder($mailbox);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 1];

    $folder = syncFolder($folder);
    $remote->setMessages([remoteMessage(1)]);
    syncFolder($folder);
    syncFolder($folder);

    Event::assertDispatchedTimes(MessageReceived::class, 1);
});

it('does not announce mail from before the initial sync when another client moves it later', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    $archive = folder($mailbox, 'Archive', FolderRole::Archive);
    remoteFolder($mailbox, 'INBOX')->status = ['UIDVALIDITY' => 1];
    remoteFolder($mailbox, 'Archive')->status = ['UIDVALIDITY' => 1];
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(1)]);

    $inbox = syncFolder($inbox);
    $archive = syncFolder($archive);

    remoteFolder($mailbox, 'INBOX')->setMessages([]);
    $moved = email('Message 1');
    $moved->getHeaders()->addIdHeader('Message-ID', 'message-1@example.org');
    remoteFolder($mailbox, 'Archive')->setMessages([remoteMessage(5, email: $moved)]);

    syncFolder($inbox);
    syncFolder($archive);

    expect(MailboxMessage::query()->pluck('uid')->all())->toBe([5]);
    Event::assertNotDispatched(MessageReceived::class);
});
