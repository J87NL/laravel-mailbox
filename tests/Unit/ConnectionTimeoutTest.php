<?php

declare(strict_types=1);

use J87NL\Mailbox\Connection\MailboxConnector;

it('passes the configured timeout to IMAP and SMTP', function (): void {
    config()->set('mailbox.connection.timeout', 7);
    $mailbox = mailbox();
    $connector = new MailboxConnector;

    expect($connector->imap($mailbox)->config('timeout'))->toBe(7)
        ->and($connector->smtp($mailbox)->getStream()->getTimeout())->toBe(7.0);
});

it('uses ten seconds by default', function (): void {
    expect((new MailboxConnector)->imap(mailbox())->config('timeout'))->toBe(10);
});
