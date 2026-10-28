<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
{
    protected $model = Mailbox::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'active' => true,
            'email' => 'team@example.com',
            'imap_host' => 'imap.example.com',
            'imap_password' => 'imap-secret',
            'imap_username' => 'team@example.com',
            'name' => 'Team',
            'smtp_host' => 'smtp.example.com',
            'smtp_password' => 'smtp-secret',
            'smtp_username' => 'team@example.com',
        ];
    }

    public function inactive(): self
    {
        return $this->state([
            'active' => false,
        ]);
    }

    public function withInbox(): self
    {
        return $this->has(
            MailboxFolder::factory()->state([
                'name' => 'INBOX',
                'path' => 'INBOX',
                'role' => FolderRole::Inbox,
            ]),
            'folders',
        );
    }
}
