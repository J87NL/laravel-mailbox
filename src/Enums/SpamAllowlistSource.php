<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Enums;

enum SpamAllowlistSource: string
{
    case Default = 'default';
    case Manual = 'manual';
    case NotJunk = 'not_junk';
}
