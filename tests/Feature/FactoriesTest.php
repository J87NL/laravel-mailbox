<?php

declare(strict_types=1);

use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;

it('creates an active mailbox with an inbox', function (): void {
    $mailbox = Mailbox::factory()
        ->withInbox()
        ->create();

    expect($mailbox->active)->toBeTrue()
        ->and($mailbox->imap_password)->toBe('imap-secret')
        ->and($mailbox->folderWithRole(FolderRole::Inbox)?->path)->toBe('INBOX');
});

it('creates an inactive mailbox', function (): void {
    expect(Mailbox::factory()->inactive()->create()->active)->toBeFalse();
});

it('creates messages in the mailbox of their folder', function (): void {
    $folder = MailboxFolder::factory()
        ->role(FolderRole::Archive)
        ->create();

    $messages = MailboxMessage::factory()
        ->count(2)
        ->for($folder, 'folder')
        ->seen()
        ->create();

    expect($folder->path)->toBe('Archive')
        ->and($messages->pluck('mailbox_id')->unique()->all())->toBe([$folder->mailbox_id])
        ->and($messages->pluck('uid')->unique())->toHaveCount(2)
        ->and($messages->every(fn (MailboxMessage $message): bool => $message->is_seen))->toBeTrue();
});
