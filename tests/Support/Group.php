<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $table = 'groups';

    protected $guarded = [];
}
