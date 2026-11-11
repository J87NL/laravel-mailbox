<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use J87NL\Mailbox\Actions\MarkJunk;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Sync\SyncFolderMessages;

/**
 * @param  array<string, string>  $headers
 */
function serverSpamMessage(int $uid, array $headers): FakeMessage
{
    $email = email(sprintf('Message %d', $uid));

    foreach ($headers as $name => $value) {
        $email->getHeaders()->addTextHeader($name, $value);
    }

    return remoteMessage($uid, email: $email);
}

/**
 * @param  array<int, FakeMessage>  $messages
 * @return array<int, array{server_spam_flag: bool|null, server_spam_score: float|null}>
 */
function syncServerSpam(array $messages): array
{
    $mailbox = mailbox();
    $folder = folder($mailbox);
    remoteFolder($mailbox, 'INBOX')->setMessages($messages);

    app(SyncFolderMessages::class)->handle($folder);

    return MailboxMessage::query()
        ->orderBy('uid')
        ->get()
        ->mapWithKeys(fn (MailboxMessage $message): array => [
            $message->uid => $message->only([
                'server_spam_flag',
                'server_spam_score',
            ]),
        ])
        ->all();
}

/**
 * @return array<string, string>
 */
function transipHeaders(string $flag, string $score): array
{
    return [
        'X-Spam-Flag' => $flag,
        'X-Spam-Score' => $score,
    ];
}

it('stores the server spam flag and score of synced messages', function (): void {
    $stored = syncServerSpam([
        serverSpamMessage(1, transipHeaders('YES', '6.95')),
        serverSpamMessage(2, transipHeaders('NO', '-1.2')),
        serverSpamMessage(3, transipHeaders(' yes ', '12')),
        serverSpamMessage(4, [
            'X-Spam-Flag' => 'No',
        ]),
        serverSpamMessage(5, []),
        serverSpamMessage(6, transipHeaders('maybe', 'high')),
        serverSpamMessage(7, [
            'X-Spam-Score' => '123456789',
        ]),
    ]);

    expect($stored)->toBe([
        1 => [
            'server_spam_flag' => true,
            'server_spam_score' => 6.95,
        ],
        2 => [
            'server_spam_flag' => false,
            'server_spam_score' => -1.2,
        ],
        3 => [
            'server_spam_flag' => true,
            'server_spam_score' => 12.0,
        ],
        4 => [
            'server_spam_flag' => false,
            'server_spam_score' => null,
        ],
        5 => [
            'server_spam_flag' => null,
            'server_spam_score' => null,
        ],
        6 => [
            'server_spam_flag' => null,
            'server_spam_score' => null,
        ],
        7 => [
            'server_spam_flag' => null,
            'server_spam_score' => null,
        ],
    ]);
});

it('reads the header names from the config', function (): void {
    config()->set('mailbox.spam.server_headers', [
        'flag' => 'X-Custom-Spam',
        'score' => 'X-Custom-Score',
    ]);

    $stored = syncServerSpam([
        serverSpamMessage(1, [
            ...transipHeaders('NO', '1'),
            'X-Custom-Spam' => 'YES',
            'X-Custom-Score' => '8.5',
        ]),
    ]);

    expect($stored[1])->toBe([
        'server_spam_flag' => true,
        'server_spam_score' => 8.5,
    ]);
});

it('stores nothing for a header the config disables', function (): void {
    config()->set('mailbox.spam.server_headers', [
        'flag' => null,
        'score' => '',
    ]);

    $stored = syncServerSpam([
        serverSpamMessage(1, transipHeaders('YES', '6.95')),
    ]);

    expect($stored[1])->toBe([
        'server_spam_flag' => null,
        'server_spam_score' => null,
    ]);
});

it('uses the default header names when a published spam config lacks them', function (): void {
    config()->set('mailbox.spam', [
        'queue' => null,
    ]);

    $stored = syncServerSpam([
        serverSpamMessage(1, transipHeaders('YES', '6.95')),
    ]);

    expect($stored[1])->toBe([
        'server_spam_flag' => true,
        'server_spam_score' => 6.95,
    ]);
});

it('reads the verdict from the peeked headers without an extra fetch', function (): void {
    $mailbox = mailbox();
    $folder = folder($mailbox);
    $remote = remoteFolder($mailbox, 'INBOX');
    $remote->addScoredMessage(1, email()->toString(), 'YES', '6.95');

    app(SyncFolderMessages::class)->handle($folder);

    expect($remote->fetchItems)->toBe([
        [
            'FLAGS',
            'RFC822.SIZE',
            'BODY.PEEK[HEADER]',
            'BODYSTRUCTURE',
        ],
    ])
        ->and($remote->messageFetches)->toBe(0)
        ->and($remote->message(1)->flags())->toBe([])
        ->and(MailboxMessage::query()
            ->sole()
            ->only([
                'server_spam_flag',
                'server_spam_score',
            ]))->toBe([
                'server_spam_flag' => true,
                'server_spam_score' => 6.95,
            ]);
});

it('keeps the last occurrence, which the receiving server adds', function (string $forgedFlag, string $forgedScore, string $serverFlag, string $serverScore, bool $prepend, bool $flag, float $score): void {
    Log::spy();
    $mailbox = mailbox();
    $folder = folder($mailbox);
    $email = email();
    $email->getHeaders()->addTextHeader('X-Spam-Flag', $forgedFlag);
    $email->getHeaders()->addTextHeader('X-Spam-Score', $forgedScore);
    remoteFolder($mailbox, 'INBOX')->addScoredMessage(1, $email->toString(), $serverFlag, $serverScore, prepend: $prepend);

    app(SyncFolderMessages::class)->handle($folder);

    expect(MailboxMessage::query()
        ->sole()
        ->only([
            'server_spam_flag',
            'server_spam_score',
        ]))->toBe([
            'server_spam_flag' => $flag,
            'server_spam_score' => $score,
        ]);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $context['count'] === 2
            && in_array($context['header'], ['X-Spam-Flag', 'X-Spam-Score'], true)
            && array_key_exists('message_id', $context))
        ->twice();
})->with([
    'forged NO on top, server YES at the end' => ['NO', '-5', 'YES', '7.5', false, true, 7.5],
    'forged YES on top, server NO at the end' => ['YES', '9', 'NO', '0.4', false, false, 0.4],
    'server headers prepended, the last one wins' => ['YES', '9', 'NO', '0.4', true, true, 9.0],
]);

it('keeps the verdict when a message moved to the junk folder is synced there', function (): void {
    Bus::fake([SyncFolderJob::class]);
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    $junk = folder($mailbox, 'Spam', FolderRole::Junk);
    $remoteInbox = remoteFolder($mailbox, 'INBOX');
    $remoteJunk = remoteFolder($mailbox, 'Spam');
    $original = $remoteInbox->addScoredMessage(1, email()->toString(), 'YES', '6.95');

    app(SyncFolderMessages::class)->handle($inbox);

    app(MarkJunk::class)->junk($mailbox, MailboxMessage::query()
        ->with('folder')
        ->get(), user());

    $remoteJunk->addMessage(new FakeMessage(40, [], (string) $original));

    app(SyncFolderMessages::class)->handle($junk);

    expect(MailboxMessage::query()
        ->get()
        ->map(fn (MailboxMessage $message): array => $message->only([
            'mailbox_folder_id',
            'uid',
            'server_spam_flag',
            'server_spam_score',
        ]))
        ->all())->toBe([
            [
                'mailbox_folder_id' => $junk->id,
                'uid' => 40,
                'server_spam_flag' => true,
                'server_spam_score' => 6.95,
            ],
        ]);
});
