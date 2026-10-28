<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;

/**
 * @extends Factory<MailboxFolder>
 */
class MailboxFolderFactory extends Factory
{
    protected $model = MailboxFolder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mailbox_id' => Mailbox::factory(),
            'name' => 'INBOX',
            'path' => 'INBOX',
            'role' => FolderRole::Inbox,
            'selectable' => true,
        ];
    }

    public function role(FolderRole $role, ?string $path = null): self
    {
        $path ??= ucfirst($role->value);

        return $this->state([
            'name' => $path,
            'path' => $path,
            'role' => $role,
        ]);
    }
}
