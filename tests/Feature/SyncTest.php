<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Events\MessageReceived;
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

    expect(MailboxFolder::query()->orderBy('path')->get(['path', 'role', 'selectable'])->toArray())->toBe([
        ['path' => 'INBOX', 'role' => 'inbox', 'selectable' => true],
        ['path' => 'Old', 'role' => 'custom', 'selectable' => false],
        ['path' => 'Sent Items', 'role' => 'sent', 'selectable' => true],
    ]);
});

it('stores new messages with headers and flags and announces them', function (): void {
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

    $stored = MailboxMessage::query()->orderBy('uid')->get();

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
        ->and($folder->synced_at)->not->toBeNull();

    Event::assertDispatchedTimes(MessageReceived::class, 2);
});

it('only stores messages above the last known uid on the next run', function (): void {
    Event::fake([MessageReceived::class]);
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 4, 'uid_validity' => 1]);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 1];
    storedMessage($folder, 4);
    $remote->setMessages([remoteMessage(4), remoteMessage(6)]);

    syncFolder($folder);

    expect(MailboxMessage::query()->orderBy('uid')->pluck('uid')->all())->toBe([4, 6]);
    Event::assertDispatchedTimes(MessageReceived::class, 1);
});

it('drops the folder cache when UIDVALIDITY changes', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: ['last_uid' => 90, 'uid_validity' => 1]);
    storedMessage($folder, 90);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = ['UIDVALIDITY' => 2];
    $remote->setMessages([remoteMessage(1)]);

    $folder = syncFolder($folder);

    expect(MailboxMessage::query()->pluck('uid')->all())->toBe([1])
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

    expect(MailboxMessage::query()->orderBy('uid')->get(['uid', 'is_seen', 'is_flagged', 'is_answered'])->toArray())->toBe([
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

    expect(MailboxMessage::query()->count())->toBe(0)
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

    $stored = MailboxMessage::query()->sole();

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
