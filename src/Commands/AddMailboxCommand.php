<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Commands;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Actions\SaveMailbox;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class AddMailboxCommand extends Command
{
    protected $signature = 'mailbox:add';

    protected $description = 'Add a shared mailbox after testing its IMAP and SMTP connection';

    public function handle(SaveMailbox $save): int
    {
        $email = text('Email address', required: true, validate: ['email' => 'email:rfc']);
        $authentication = select('Authentication', ['plain' => 'Username and password', 'oauth' => 'OAuth 2.0'], 'plain');
        $username = text('Username', default: $email, required: true);
        $secret = $authentication === 'plain' ? password('Password', required: true) : null;

        $data = [
            'append_sent' => confirm('Store sent mail in the Sent folder? (disable for Gmail and Microsoft 365)', true),
            'authentication' => $authentication,
            'email' => $email,
            'from_name' => text('Sender name', default: config('app.name')),
            'imap_encryption' => select('IMAP encryption', ['ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS'], 'ssl'),
            'imap_host' => text('IMAP host', required: true),
            'imap_password' => $secret,
            'imap_port' => (int) text('IMAP port', default: '993', required: true),
            'imap_username' => $username,
            'name' => text('Display name', default: $email, required: true),
            'smtp_host' => text('SMTP host', required: true),
            'smtp_password' => $secret,
            'smtp_port' => (int) text('SMTP port', default: '587', required: true),
            'smtp_username' => $username,
        ];

        try {
            $mailbox = $save->handle(null, $data, null);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->components->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $this->components->info("Mailbox [{$mailbox->name}] added with id {$mailbox->id}. Grant access with `php artisan mailbox:grant {$mailbox->id}`.");

        return self::SUCCESS;
    }
}
