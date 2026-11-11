<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Contracts;

use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Spam\SpamVerdict;

interface ClassifiesSpam
{
    public function classify(MailboxMessage $message): SpamVerdict;
}
