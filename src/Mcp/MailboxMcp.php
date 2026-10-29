<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Mcp;

use J87NL\Mailbox\Mcp\Tools\GetMailboxMessageTool;
use J87NL\Mailbox\Mcp\Tools\ListMailboxesTool;
use J87NL\Mailbox\Mcp\Tools\ListMailboxMessagesTool;
use J87NL\Mailbox\Mcp\Tools\MailboxTool;
use J87NL\Mailbox\Mcp\Tools\UpdateMailboxMessageTool;

class MailboxMcp
{
    /**
     * @return array<int, class-string<MailboxTool>>
     */
    public static function tools(): array
    {
        return [
            ListMailboxesTool::class,
            ListMailboxMessagesTool::class,
            GetMailboxMessageTool::class,
            UpdateMailboxMessageTool::class,
        ];
    }

    /**
     * @return array<int, class-string<MailboxTool>>
     */
    public static function readTools(): array
    {
        return [
            ListMailboxesTool::class,
            ListMailboxMessagesTool::class,
            GetMailboxMessageTool::class,
        ];
    }
}
