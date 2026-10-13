<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use J87NL\Mailbox\Concerns\HasMailboxMessages;

class Group extends Model
{
    use HasMailboxMessages;

    protected $table = 'groups';

    protected $guarded = [];
}
