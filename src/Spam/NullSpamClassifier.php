<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use J87NL\Mailbox\Contracts\ClassifiesSpam;
use J87NL\Mailbox\Models\MailboxMessage;

class NullSpamClassifier implements ClassifiesSpam
{
    public function classify(MailboxMessage $message): SpamVerdict
    {
        return SpamVerdict::none();
    }
}
