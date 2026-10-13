<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Connection;

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Support\Str;

class UidPlusConnection extends ImapConnection
{
    /**
     * @param  array<int, int>  $uids
     */
    public function uidExpunge(array $uids): void
    {
        $tag = null;

        $this->send('UID EXPUNGE', [Str::set($uids)], $tag);

        $this->assertTaggedResponse($tag);
    }
}
