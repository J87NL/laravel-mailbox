<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Tests\Support;

use J87NL\Mailbox\Mcp\MailboxMcp;
use Laravel\Mcp\Server;

class McpServer extends Server
{
    protected function boot(): void
    {
        $this->tools = MailboxMcp::tools();
    }
}
