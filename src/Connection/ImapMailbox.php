<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Connection;

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Mailbox;

class ImapMailbox extends Mailbox
{
    private ?float $readDeadline = null;

    private ?TimeoutImapStream $stream = null;

    public function connect(?ConnectionInterface $connection = null): void
    {
        if (is_null($connection)) {
            $this->stream = new TimeoutImapStream(max(1, (int) $this->config('read_timeout', MailboxConnector::DEFAULT_READ_TIMEOUT)));
            $this->stream->readUntil($this->readDeadline);
        }

        parent::connect($connection ?? new UidPlusConnection($this->stream));
    }

    public function readUntil(?float $deadline): void
    {
        $this->readDeadline = $deadline;
        $this->stream?->readUntil($deadline);
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
