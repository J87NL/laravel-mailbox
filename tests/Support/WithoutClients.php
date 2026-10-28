<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Tests\Support;

use Illuminate\Database\Eloquent\Builder;

class WithoutClients
{
    public function __invoke(Builder $query): void
    {
        $query->where('email', 'not like', '%@client.example');
    }
}
