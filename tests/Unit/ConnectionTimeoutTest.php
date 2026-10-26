<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Connection\TimeoutImapStream;

it('passes the configured timeout to IMAP and SMTP', function (): void {
    config()->set('mailbox.connection.timeout', 7);
    $mailbox = mailbox();
    $connector = new MailboxConnector;

    expect($connector->imap($mailbox)->config('timeout'))->toBe(7)
        ->and($connector->smtp($mailbox)->getStream()->getTimeout())->toBe(7.0);
});

it('uses ten seconds to connect and fifteen seconds to read by default', function (): void {
    $imap = (new MailboxConnector)->imap(mailbox());

    expect($imap->config('timeout'))->toBe(10)
        ->and($imap->config('read_timeout'))->toBe(15);
});

it('passes the configured read timeout to IMAP', function (): void {
    config()->set('mailbox.connection.read_timeout', 12);

    expect((new MailboxConnector)->imap(mailbox())->config('read_timeout'))->toBe(12);
});

it('gives up on a server that stops answering after the read timeout', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) Str::afterLast((string) stream_socket_get_name($server, false), ':');
    $stream = new TimeoutImapStream(1);
    $stream->open('tcp', '127.0.0.1', $port, 1);
    $started = microtime(true);

    expect($stream->fgets())->toBeFalse()
        ->and($stream->read(10))->toBeFalse()
        ->and($stream->meta()['timed_out'])->toBeTrue()
        ->and(microtime(true) - $started)->toBeLessThan(5);

    $stream->close();
    fclose($server);
});
