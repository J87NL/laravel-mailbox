<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Tests\Support;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_user', 'user_id');
    }
}
