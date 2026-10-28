<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;

/**
 * @extends Factory<MailboxMessage>
 */
class MailboxMessageFactory extends Factory
{
    protected $model = MailboxMessage::class;

    private static int $uid = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uid = ++self::$uid;

        return [
            'from_email' => 'sender@example.org',
            'from_name' => 'Sender',
            'mailbox_folder_id' => MailboxFolder::factory(),
            'mailbox_id' => fn (array $attributes): int => MailboxFolder::query()
                ->findOrFail($attributes['mailbox_folder_id'])
                ->mailbox_id,
            'message_id' => sprintf('message-%d@example.org', $uid),
            'sent_at' => now(),
            'subject' => sprintf('Message %d', $uid),
            'to' => [
                [
                    'email' => 'team@example.com',
                    'name' => 'Team',
                ],
            ],
            'uid' => $uid,
        ];
    }

    public function seen(): self
    {
        return $this->state([
            'is_seen' => true,
        ]);
    }
}
