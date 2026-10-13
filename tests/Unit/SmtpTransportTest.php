<?php

declare(strict_types=1);

use J87NL\Mailbox\Connection\MailboxConnector;

it('requires TLS for SMTP unless the mailbox explicitly opts out', function (?bool $smtpTls, bool $required): void {
    $transport = (new MailboxConnector)->smtp(mailbox(['smtp_tls' => $smtpTls]));

    expect($transport->isTlsRequired())->toBe($required);
})->with([
    'default' => [null, true],
    'implicit tls' => [true, true],
    'explicit opt-out' => [false, false],
]);
