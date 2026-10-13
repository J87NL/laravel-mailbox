<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

class EmailList implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $addresses = array_filter(array_map('trim', preg_split('/[,;]+/', (string) $value)));

        foreach ($addresses as $address) {
            if (Validator::make(['email' => $address], ['email' => 'email:rfc'])->fails()) {
                $fail(__('mailbox::mailbox.invalid_address', ['address' => $address]));

                return;
            }
        }
    }
}
