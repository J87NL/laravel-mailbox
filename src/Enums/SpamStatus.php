<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Enums;

enum SpamStatus: string
{
    case Moved = 'moved';
    case Released = 'released';
    case Suggested = 'suggested';
}
