<?php

declare(strict_types=1);

use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Sync\SyncFolderMessages;
use J87NL\Mailbox\Threading\ConversationKeys;

function threaded(MailboxMessage $message): string
{
    app(ConversationKeys::class)->assign([$message]);

    return (string) $message->refresh()->thread_key;
}

it('threads replies on the first reference and keeps the root in its own thread', function (): void {
    $folder = folder(mailbox());
    $root = storedMessage($folder, 1, [
        'message_id' => 'root@example.org',
    ]);
    $reply = storedMessage($folder, 2, [
        'in_reply_to' => '<root@example.org>',
        'message_id' => 'reply@example.org',
        'references' => '<root@example.org>',
        'subject' => 'Re: Question',
    ]);
    $second = storedMessage($folder, 3, [
        'in_reply_to' => '<reply@example.org>',
        'message_id' => 'second@example.org',
        'references' => "<root@example.org>\r\n <reply@example.org>",
    ]);

    expect(threaded($root))->toBe('root@example.org')
        ->and(threaded($reply))->toBe('root@example.org')
        ->and(threaded($second))->toBe('root@example.org');
});

it('follows a chain of In-Reply-To headers through messages it already knows', function (): void {
    $folder = folder(mailbox());
    $sent = folder($folder->mailbox, 'Sent', FolderRole::Sent);
    $root = storedMessage($folder, 1, [
        'message_id' => 'root@example.org',
    ]);
    $reply = storedMessage($sent, 1, [
        'in_reply_to' => 'root@example.org',
        'message_id' => 'reply@example.org',
    ]);
    $answer = storedMessage($folder, 2, [
        'in_reply_to' => '<reply@example.org>',
        'message_id' => 'answer@example.org',
    ]);

    threaded($root);
    threaded($reply);

    expect(threaded($answer))->toBe('root@example.org');
});

it('falls back to the normalized subject for a reply without threading headers', function (): void {
    $folder = folder(mailbox());
    $root = storedMessage($folder, 1, [
        'message_id' => 'root@example.org',
        'sent_at' => now()->subDay(),
        'subject' => 'Opening hours  100%_sure',
    ]);
    $reply = storedMessage($folder, 2, [
        'message_id' => 'loose@example.org',
        'subject' => 'AW: Re: opening hours 100%_sure',
    ]);
    $newsletter = storedMessage($folder, 3, [
        'message_id' => 'news-1@example.org',
        'subject' => 'Opening hours 100%_sure',
    ]);
    $unrelated = storedMessage($folder, 4, [
        'message_id' => 'other@example.org',
        'subject' => 'Re: Opening hours 100Xsure',
    ]);

    threaded($root);

    expect(threaded($reply))->toBe('root@example.org')
        ->and(threaded($newsletter))->toBe('news-1@example.org')
        ->and(threaded($unrelated))->toBe('other@example.org');
});

it('keys a message without any id on its normalized subject', function (): void {
    $folder = folder(mailbox());
    $first = storedMessage($folder, 1, [
        'message_id' => null,
        'subject' => 'Fwd: Report',
    ]);
    $second = storedMessage($folder, 2, [
        'message_id' => null,
        'subject' => 'report',
    ]);

    expect(threaded($first))->toBe(sprintf('subject:%s', sha1('report')))
        ->and(threaded($second))->toBe(threaded($first));
});

it('never joins a thread of another mailbox', function (): void {
    $other = folder(mailbox([
        'name' => 'Other',
    ]));
    threaded(storedMessage($other, 1, [
        'message_id' => 'root@example.org',
        'thread_key' => null,
    ]));
    $mine = storedMessage(folder(mailbox()), 1, [
        'message_id' => 'loose@example.org',
        'subject' => 'Re: Message 1',
    ]);

    expect(threaded($mine))->toBe('loose@example.org');
});

it('gives synchronised messages their conversation key', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox, attributes: [
        'uid_validity' => 1,
    ]);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->status = [
        'UIDVALIDITY' => 1,
    ];
    $reply = email('Re: Message 1');
    $reply->getHeaders()->addIdHeader('In-Reply-To', 'message-1@example.org');
    $reply->getHeaders()->addIdHeader('References', 'message-1@example.org');
    $remote->setMessages([
        remoteMessage(1),
        remoteMessage(2, [], $reply),
    ]);

    app(SyncFolderMessages::class)->handle($folder);

    expect(MailboxMessage::query()
        ->orderBy('uid')
        ->pluck('thread_key')
        ->all())->toBe([
            'message-1@example.org',
            'message-1@example.org',
        ]);
});

it('threads stored messages oldest first from the command', function (): void {
    $folder = folder(mailbox());
    $reply = storedMessage($folder, 2, [
        'in_reply_to' => 'root@example.org',
        'message_id' => 'reply@example.org',
        'sent_at' => now()->subDay(),
    ]);
    $answer = storedMessage($folder, 3, [
        'in_reply_to' => 'reply@example.org',
        'message_id' => 'answer@example.org',
        'sent_at' => now(),
    ]);
    storedMessage($folder, 1, [
        'message_id' => 'root@example.org',
        'sent_at' => now()->subDays(2),
        'thread_key' => 'stale',
    ]);

    $this->artisan('mailbox:threads')
        ->expectsOutputToContain('2 messages threaded.')
        ->assertSuccessful();

    expect($reply->refresh()->thread_key)->toBe('stale')
        ->and($answer->refresh()->thread_key)->toBe('stale');

    $this->artisan('mailbox:threads', [
        '--fresh' => true,
    ])->assertSuccessful();

    expect(MailboxMessage::query()
        ->pluck('thread_key')
        ->unique()
        ->all())->toBe([
            'root@example.org',
        ]);
});

it('joins the newest recent message with the same subject and ignores old ones', function (): void {
    config()->set('mailbox.threading.subject_days', 30);
    $folder = folder(mailbox());
    threaded(storedMessage($folder, 1, [
        'message_id' => 'ancient@example.org',
        'sent_at' => now()->subDays(60),
        'subject' => 'Weekly report',
    ]));
    threaded(storedMessage($folder, 2, [
        'message_id' => 'older@example.org',
        'sent_at' => now()->subDays(20),
        'subject' => 'WEEKLY report',
    ]));
    threaded(storedMessage($folder, 3, [
        'message_id' => 'newer@example.org',
        'sent_at' => now()->subDays(2),
        'subject' => 'Weekly report',
    ]));
    $reply = storedMessage($folder, 4, [
        'message_id' => 'loose@example.org',
        'sent_at' => now(),
        'subject' => 'Re: weekly REPORT',
    ]);
    $late = storedMessage(folder(mailbox([
        'name' => 'Other',
    ])), 1, [
        'message_id' => 'late@example.org',
        'subject' => 'Re: Weekly report',
    ]);

    expect(threaded($reply))->toBe('newer@example.org')
        ->and(threaded($late))->toBe('late@example.org');
});

it('moves replies that arrived before their parent into the parent conversation', function (): void {
    $folder = folder(mailbox());
    $root = storedMessage($folder, 1, [
        'message_id' => 'root@example.org',
    ]);
    $answer = storedMessage($folder, 3, [
        'in_reply_to' => 'reply@example.org',
        'message_id' => 'answer@example.org',
    ]);
    $reply = storedMessage($folder, 2, [
        'in_reply_to' => 'root@example.org',
        'message_id' => 'reply@example.org',
    ]);
    $other = storedMessage(folder(mailbox([
        'name' => 'Other',
    ])), 1, [
        'message_id' => 'elsewhere@example.org',
        'thread_key' => 'reply@example.org',
    ]);

    threaded($root);

    expect(threaded($answer))->toBe('reply@example.org')
        ->and(threaded($reply))->toBe('root@example.org')
        ->and($answer->refresh()->thread_key)->toBe('root@example.org')
        ->and($other->refresh()->thread_key)->toBe('reply@example.org');
});

it('ignores a known parent of another mailbox', function (): void {
    threaded(storedMessage(folder(mailbox([
        'name' => 'Other',
    ])), 1, [
        'message_id' => 'parent@example.org',
        'thread_key' => null,
        'references' => '<origin@example.org>',
    ]));
    $mine = storedMessage(folder(mailbox()), 1, [
        'in_reply_to' => 'parent@example.org',
        'message_id' => 'mine@example.org',
    ]);

    expect(threaded($mine))->toBe('parent@example.org');
});

it('threads only the given mailbox from the command', function (): void {
    $mine = storedMessage(folder(mailbox()), 1);
    $other = storedMessage(folder(mailbox([
        'name' => 'Other',
    ])), 1);

    $this->artisan('mailbox:threads', [
        'mailbox' => (string) $mine->mailbox_id,
    ])->assertSuccessful();

    expect($mine->refresh()->thread_key)->toBe($mine->message_id)
        ->and($other->refresh()->thread_key)->toBeNull();
});
