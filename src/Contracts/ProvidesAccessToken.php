<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Contracts;

use J87NL\Mailbox\Models\Mailbox;

interface ProvidesAccessToken
{
    public function accessToken(Mailbox $mailbox): string;
}
