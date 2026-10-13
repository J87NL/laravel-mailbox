<?php

declare(strict_types=1);

use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\MailboxMessageLink;
use J87NL\Mailbox\Tests\Support\Group;

it('returns linked messages of the linked mailbox only, once per message', function (): void {
    $group = Group::query()
        ->create(['name' => 'Project']);
    $mailbox = mailbox();
    $other = mailbox(['name' => 'Other']);
    $inbox = folder($mailbox);
    $archive = folder($mailbox, 'Archive', FolderRole::Archive);
    $linked = storedMessage($inbox, 1, ['message_id' => 'shared@example.org']);
    storedMessage($archive, 2, ['message_id' => 'shared@example.org']);
    storedMessage(folder($other), 3, ['message_id' => 'shared@example.org']);
    storedMessage($inbox, 4, ['message_id' => 'unrelated@example.org']);

    MailboxMessageLink::query()
        ->create([
            'linkable_id' => $group->id,
            'linkable_type' => $group->getMorphClass(),
            'mailbox_id' => $mailbox->id,
            'message_id' => 'shared@example.org',
        ]);

    expect($group->mailboxMessages()->pluck('id')->all())->toBe([$linked->id]);
});
