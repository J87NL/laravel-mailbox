<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Connection;

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Mailbox;

class ImapMailbox extends Mailbox
{
    public function connect(?ConnectionInterface $connection = null): void
    {
        parent::connect($connection ?? new UidPlusConnection(new TimeoutImapStream(max(1, (int) $this->config('read_timeout', MailboxConnector::DEFAULT_READ_TIMEOUT)))));
    }

    public function supportsUidPlus(): bool
    {
        return in_array('UIDPLUS', array_map('strtoupper', $this->capabilities()), true);
    }

    /**
     * @param  array<int, int>  $uids
     */
    public function expungeUids(array $uids): bool
    {
        $connection = $this->connection();

        if (! $connection instanceof UidPlusConnection || ! $this->supportsUidPlus()) {
            return false;
        }

        $connection->uidExpunge($uids);

        return true;
    }
}
