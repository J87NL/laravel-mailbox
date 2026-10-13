<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\Mailbox;
use Throwable;

class TestConnection
{
    public function __construct(
        private readonly MailboxConnector $connector,
    ) {}

    /**
     * @return array{imap: ?string, smtp: ?string, folders: int}
     */
    public function handle(Mailbox $mailbox): array
    {
        $result = ['folders' => 0, 'imap' => null, 'smtp' => null];

        try {
            $result['folders'] = count($this->connector->imap($mailbox)->folders()->get());
        } catch (Throwable $exception) {
            $result['imap'] = $exception->getMessage();
        } finally {
            rescue(fn () => $this->connector->disconnect($mailbox), report: false);
        }

        try {
            $transport = $this->connector->smtp($mailbox);
            $transport->start();
            $transport->stop();
        } catch (Throwable $exception) {
            $result['smtp'] = $exception->getMessage();
        }

        return $result;
    }

    public function passes(array $result): bool
    {
        return is_null($result['imap']) && is_null($result['smtp']);
    }
}
