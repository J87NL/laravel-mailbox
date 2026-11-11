<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Enums;

enum SpamFilterMode: string
{
    case Auto = 'auto';
    case Measure = 'measure';
    case Off = 'off';
    case Suggest = 'suggest';

    public function isActive(): bool
    {
        return $this !== self::Off;
    }

    public function label(): string
    {
        return __(sprintf('mailbox::mailbox.spam_filter_mode_%s', $this->value));
    }
}
