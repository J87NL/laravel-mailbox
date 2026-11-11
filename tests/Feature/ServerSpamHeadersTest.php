<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Testing\FakeMessage;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Sync\SyncFolderMessages;

/**
 * @param  array<string, string>  $headers
 */
function messageWithHeaders(int $uid, array $headers): FakeMessage
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
        ->mapWithKeys(fn (MailboxMessage $message): array => [$message->uid => $message->only(['server_spam_flag', 'server_spam_score'])])
        ->all();
}

it('stores the server spam flag and score of synced messages', function (): void {
    $stored = syncServerSpam([
        messageWithHeaders(1, ['X-Spam-Flag' => 'YES', 'X-Spam-Score' => '6.95']),
        messageWithHeaders(2, ['X-Spam-Flag' => 'NO', 'X-Spam-Score' => '-1.2']),
        messageWithHeaders(3, ['X-Spam-Flag' => ' yes ', 'X-Spam-Score' => '12']),
        messageWithHeaders(4, ['X-Spam-Flag' => 'No']),
        messageWithHeaders(5, []),
        messageWithHeaders(6, ['X-Spam-Flag' => 'maybe', 'X-Spam-Score' => 'high']),
        messageWithHeaders(7, ['X-Spam-Score' => '123456789']),
    ]);

    expect($stored)->toBe([
        1 => ['server_spam_flag' => true, 'server_spam_score' => 6.95],
        2 => ['server_spam_flag' => false, 'server_spam_score' => -1.2],
        3 => ['server_spam_flag' => true, 'server_spam_score' => 12.0],
        4 => ['server_spam_flag' => false, 'server_spam_score' => null],
        5 => ['server_spam_flag' => null, 'server_spam_score' => null],
        6 => ['server_spam_flag' => null, 'server_spam_score' => null],
        7 => ['server_spam_flag' => null, 'server_spam_score' => null],
    ]);
});

it('reads the header names from the config', function (): void {
    config()->set('mailbox.spam.server_headers', [
        'flag' => 'X-Custom-Spam',
        'score' => 'X-Custom-Score',
    ]);

    $stored = syncServerSpam([
        messageWithHeaders(1, ['X-Spam-Flag' => 'NO', 'X-Spam-Score' => '1', 'X-Custom-Spam' => 'YES', 'X-Custom-Score' => '8.5']),
    ]);

    expect($stored[1])->toBe(['server_spam_flag' => true, 'server_spam_score' => 8.5]);
});

it('stores nothing for a header the config disables', function (): void {
    config()->set('mailbox.spam.server_headers', [
        'flag' => null,
        'score' => '',
    ]);

    $stored = syncServerSpam([
        messageWithHeaders(1, ['X-Spam-Flag' => 'YES', 'X-Spam-Score' => '6.95']),
    ]);

    expect($stored[1])->toBe(['server_spam_flag' => null, 'server_spam_score' => null]);
});
