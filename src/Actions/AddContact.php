<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Support\Str;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxContact;

class AddContact
{
    public function handle(Mailbox $mailbox, string $email, ?string $name = null): MailboxContact
    {
        return MailboxContact::query()
            ->firstOrCreate(
                [
                    'email' => Str::lower(trim($email)),
                    'mailbox_id' => $mailbox->id,
                ],
                [
                    'name' => $name === '' ? null : $name,
                ],
            );
    }
}
