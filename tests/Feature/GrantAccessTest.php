<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Actions\GrantAccess;
use J87NL\Mailbox\Enums\AccessLevel;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Models\MailboxAccess;
use J87NL\Mailbox\Models\MailboxAuditEntry;

it('grants, changes and revokes access with an audit trail', function (): void {
    $mailbox = mailbox();
    $member = user();
    $grant = app(GrantAccess::class);

    $grant->handle($mailbox, $member->getMorphClass(), $member->id, AccessLevel::Read, null);
    $access = $grant->handle($mailbox, $member->getMorphClass(), $member->id, AccessLevel::Write, null);
    $grant->revoke($access, null);

    expect(MailboxAccess::query()
        ->count())->toBe(0)
        ->and(MailboxAuditEntry::query()
            ->pluck('action')->all())->toBe([
                AuditAction::AccessGranted,
                AuditAction::AccessGranted,
                AuditAction::AccessRevoked,
            ]);
});

it('refuses to revoke the last manage row of a mailbox', function (): void {
    $mailbox = mailbox();
    $manager = user();
    $access = app(GrantAccess::class)->handle($mailbox, $manager->getMorphClass(), $manager->id, AccessLevel::Manage, null);

    expect(fn (): null => app(GrantAccess::class)->revoke($access, null))->toThrow(ValidationException::class);
    expect(MailboxAccess::query()
        ->count())->toBe(1);
});

it('refuses to lower the last manage row of a mailbox', function (): void {
    $mailbox = mailbox();
    $manager = user();
    $grant = app(GrantAccess::class);
    $grant->handle($mailbox, $manager->getMorphClass(), $manager->id, AccessLevel::Manage, null);

    expect(fn (): MailboxAccess => $grant->handle($mailbox, $manager->getMorphClass(), $manager->id, AccessLevel::Read, null))
        ->toThrow(ValidationException::class);
    expect(MailboxAccess::query()
        ->sole()->level)->toBe(AccessLevel::Manage);
});

it('revokes a manage row while another manager remains', function (): void {
    $mailbox = mailbox();
    $grant = app(GrantAccess::class);
    $first = user();
    $second = user();
    $access = $grant->handle($mailbox, $first->getMorphClass(), $first->id, AccessLevel::Manage, null);
    $grant->handle($mailbox, $second->getMorphClass(), $second->id, AccessLevel::Manage, null);

    $grant->revoke($access, null);

    expect(MailboxAccess::query()
        ->pluck('accessor_id')->all())->toBe([$second->id]);
});
