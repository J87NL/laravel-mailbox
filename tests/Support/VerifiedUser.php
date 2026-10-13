<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Tests\Support;

use Illuminate\Auth\MustVerifyEmail as VerifiesEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class VerifiedUser extends User implements MustVerifyEmail
{
    use VerifiesEmail;
}
