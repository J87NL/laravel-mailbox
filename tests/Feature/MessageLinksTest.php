<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
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

it('creates a link through the relation in the message its mailbox', function (): void {
    $group = Group::query()
        ->create(['name' => 'Project']);
    $message = storedMessage(folder(mailbox()), 1, ['message_id' => 'question@example.org']);

    $link = $message->links()->create([
        'linkable_id' => $group->id,
        'linkable_type' => $group->getMorphClass(),
    ]);
    $again = $message->links()->firstOrCreate([
        'linkable_id' => $group->id,
        'linkable_type' => $group->getMorphClass(),
    ]);

    expect($link->mailbox_id)->toBe($message->mailbox_id)
        ->and($link->message_id)->toBe('question@example.org')
        ->and($again->id)->toBe($link->id);
});

it('counts and queries the links per mailbox when both mailboxes link the same Message-ID', function (): void {
    $group = Group::query()
        ->create(['name' => 'Project']);
    $first = mailbox();
    $second = mailbox(['name' => 'Other']);
    $firstMessage = storedMessage(folder($first), 1, ['message_id' => 'shared@example.org']);
    $secondMessage = storedMessage(folder($second), 2, ['message_id' => 'shared@example.org']);
    $unlinked = storedMessage(folder($first, 'Archive', FolderRole::Archive), 3, ['message_id' => 'alone@example.org']);
    $withoutId = storedMessage(folder($second, 'Archive', FolderRole::Archive), 4, ['message_id' => null]);

    foreach ([$first, $second] as $mailbox) {
        MailboxMessageLink::query()
            ->create([
                'linkable_id' => $group->id,
                'linkable_type' => $group->getMorphClass(),
                'mailbox_id' => $mailbox->id,
                'message_id' => 'shared@example.org',
            ]);
    }

    $counts = MailboxMessage::query()
        ->withCount('links')
        ->pluck('links_count', 'id')
        ->all();
    $eager = MailboxMessage::query()
        ->with('links')
        ->get()
        ->keyBy('id');
    $filtered = MailboxMessage::query()
        ->whereHas('links', fn (Builder $query): Builder => $query->where('mailbox_id', $second->id))
        ->pluck('id')
        ->all();

    expect($counts)->toBe([
        $firstMessage->id => 1,
        $secondMessage->id => 1,
        $unlinked->id => 0,
        $withoutId->id => 0,
    ])
        ->and($eager[$firstMessage->id]->links->pluck('mailbox_id')->all())->toBe([$first->id])
        ->and($eager[$secondMessage->id]->links->pluck('mailbox_id')->all())->toBe([$second->id])
        ->and($eager[$withoutId->id]->links)->toHaveCount(0)
        ->and($filtered)->toBe([$secondMessage->id]);
});

it('refuses to link a message to a model that is not saved', function (): void {
    $message = storedMessage(folder(mailbox()), 1);

    expect(fn () => app(LinkMessage::class)->handle($message, new Group(['name' => 'Draft']), null))
        ->toThrow(MailboxException::class);

    expect(MailboxMessageLink::query()->count())->toBe(0)
        ->and(MailboxAuditEntry::query()->count())->toBe(0);
});

it('keeps no link when the audit entry cannot be written', function (): void {
    $group = Group::query()
        ->create(['name' => 'Project']);
    $message = storedMessage(folder(mailbox()), 1);
    Schema::drop('mailbox_audit_entries');

    expect(fn () => app(LinkMessage::class)->handle($message, $group, null))
        ->toThrow(QueryException::class);

    expect(MailboxMessageLink::query()->count())->toBe(0);
});
