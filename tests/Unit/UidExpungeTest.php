<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use J87NL\Mailbox\Connection\ImapMailbox;
use J87NL\Mailbox\Connection\UidPlusConnection;

function connectedMailbox(FakeStream $stream, string $capabilities): ImapMailbox
{
    $stream->open();
    $stream->feed([
        '* OK IMAP ready',
        'TAG1 OK LOGIN completed',
        sprintf('* CAPABILITY %s', $capabilities),
        'TAG2 OK CAPABILITY completed',
        'TAG3 OK UID EXPUNGE completed',
    ]);

    $mailbox = new ImapMailbox([
        'host' => 'imap.example.com',
        'password' => 'secret',
        'username' => 'team@example.com',
    ]);

    $mailbox->connect(new UidPlusConnection($stream));

    return $mailbox;
}

it('expunges only the given uids when the server supports UIDPLUS', function (): void {
    $stream = new FakeStream;
    $mailbox = connectedMailbox($stream, 'IMAP4rev1 UIDPLUS MOVE');

    expect($mailbox->expungeUids([7, 9]))->toBeTrue();

    $stream->assertWritten('TAG3 UID EXPUNGE 7,9');
});

it('does not expunge at all when the server lacks UIDPLUS', function (): void {
    $stream = new FakeStream;
    $mailbox = connectedMailbox($stream, 'IMAP4rev1 MOVE');

    expect($mailbox->expungeUids([7]))->toBeFalse();

    $stream->assertNotWritten('EXPUNGE');
});
