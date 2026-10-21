<?php

declare(strict_types=1);

use J87NL\Mailbox\Actions\LinkMessage;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Exceptions\MailboxException;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxMessage;
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

it('returns the links of the message its own mailbox only', function (): void {
    $group = Group::query()
        ->create(['name' => 'Project']);
    $mailbox = mailbox();
    $other = mailbox(['name' => 'Other']);
    $message = storedMessage(folder($mailbox), 1, ['message_id' => 'shared@example.org']);
    $otherMessage = storedMessage(folder($other), 2, ['message_id' => 'shared@example.org']);

    $own = MailboxMessageLink::query()
        ->create([
            'linkable_id' => $group->id,
            'linkable_type' => $group->getMorphClass(),
            'mailbox_id' => $mailbox->id,
            'message_id' => 'shared@example.org',
        ]);

    $eager = MailboxMessage::query()
        ->with('links')
        ->orderBy('id')
        ->get();

    expect($message->links()->pluck('id')->all())->toBe([$own->id])
        ->and($otherMessage->links()->pluck('id')->all())->toBe([])
        ->and($eager[0]->links->pluck('id')->all())->toBe([$own->id])
        ->and($eager[1]->links->pluck('id')->all())->toBe([])
        ->and(MailboxMessage::query()
            ->has('links')
            ->pluck('id')
            ->all())->toBe([$message->id]);
});

it('links a message to a model once and records it in the audit trail', function (): void {
    $group = Group::query()
        ->create(['name' => 'Project']);
    $message = storedMessage(folder(mailbox()), 1, ['message_id' => 'question@example.org']);
    $actor = user();

    $link = app(LinkMessage::class)->handle($message, $group, $actor);
    $again = app(LinkMessage::class)->handle($message, $group, $actor);

    $audit = MailboxAuditEntry::query()
        ->sole();

    expect($again->id)->toBe($link->id)
        ->and($link->mailbox_id)->toBe($message->mailbox_id)
        ->and($link->message_id)->toBe('question@example.org')
        ->and($link->user_id)->toBe($actor->id)
        ->and($group->mailboxMessages()->pluck('id')->all())->toBe([$message->id])
        ->and($audit->action)->toBe(AuditAction::Linked)
        ->and($audit->message_id)->toBe('question@example.org')
        ->and($audit->subject)->toBe('Message 1')
        ->and($audit->folder_path)->toBe('INBOX')
        ->and($audit->user_id)->toBe($actor->id)
        ->and($audit->meta)->toBe(['linkable' => sprintf('%s:%d', $group->getMorphClass(), $group->id)]);
});

it('refuses to link a message without a Message-ID', function (): void {
    $group = Group::query()
        ->create(['name' => 'Project']);
    $message = storedMessage(folder(mailbox()), 1, ['message_id' => null]);

    expect(fn () => app(LinkMessage::class)->handle($message, $group, null))
        ->toThrow(MailboxException::class);

    expect(MailboxMessageLink::query()->count())->toBe(0)
        ->and(MailboxAuditEntry::query()->count())->toBe(0);
});
