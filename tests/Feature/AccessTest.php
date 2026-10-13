<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use J87NL\Mailbox\Enums\AccessLevel;
use J87NL\Mailbox\MailboxServiceProvider;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAccess;
use J87NL\Mailbox\Tests\Support\Group;
use J87NL\Mailbox\Tests\Support\User;
use J87NL\Mailbox\Tests\Support\VerifiedUser;

function grant(Mailbox $mailbox, Model $accessor, AccessLevel $level): void
{
    MailboxAccess::query()
        ->create([
            'accessor_id' => $accessor->getKey(),
            'accessor_type' => $accessor->getMorphClass(),
            'level' => $level,
            'mailbox_id' => $mailbox->id,
        ]);
}

/**
 * @return array{read: bool, write: bool, manage: bool}
 */
function abilities(?User $user, Mailbox $mailbox): array
{
    return [
        'read' => Gate::forUser($user)->allows('accessMailbox', $mailbox),
        'write' => Gate::forUser($user)->allows('workInMailbox', $mailbox),
        'manage' => Gate::forUser($user)->allows('manageMailbox', $mailbox),
    ];
}

it('grants exactly the level of a user row', function (AccessLevel $level, array $expected): void {
    $mailbox = mailbox();
    $user = user();

    grant($mailbox, $user, $level);

    expect(abilities($user, $mailbox))->toBe($expected);
})->with([
    'read' => [AccessLevel::Read, ['read' => true, 'write' => false, 'manage' => false]],
    'write' => [AccessLevel::Write, ['read' => true, 'write' => true, 'manage' => false]],
    'manage' => [AccessLevel::Manage, ['read' => true, 'write' => true, 'manage' => true]],
]);

it('grants access through a group the user belongs to', function (): void {
    $mailbox = mailbox();
    $user = user();
    $group = Group::query()
        ->create(['name' => 'Office']);
    $user->groups()->attach($group);

    grant($mailbox, $group, AccessLevel::Write);

    expect(abilities($user, $mailbox))->toBe(['read' => true, 'write' => true, 'manage' => false]);
});

it('uses the highest level over the user row and the group rows', function (): void {
    $mailbox = mailbox();
    $user = user();
    $group = Group::query()
        ->create(['name' => 'Board']);
    $user->groups()->attach($group);

    grant($mailbox, $user, AccessLevel::Read);
    grant($mailbox, $group, AccessLevel::Manage);

    expect(abilities($user, $mailbox)['manage'])->toBeTrue();
});

it('denies users without a row and guests', function (): void {
    $mailbox = mailbox();
    grant($mailbox, user(), AccessLevel::Manage);

    expect(abilities(user(), $mailbox))->toBe(['read' => false, 'write' => false, 'manage' => false])
        ->and(abilities(null, $mailbox))->toBe(['read' => false, 'write' => false, 'manage' => false]);
});

it('does not leak a row of one mailbox to another mailbox', function (): void {
    $user = user();
    grant(mailbox(['name' => 'Other']), $user, AccessLevel::Manage);

    expect(abilities($user, mailbox())['read'])->toBeFalse();
});

it('gives admins manage on every mailbox and the global ability', function (): void {
    config()->set('mailbox.access.admins', ['Admin@Example.com']);
    $admin = user(['email' => 'admin@example.com']);

    expect(abilities($admin, mailbox()))->toBe(['read' => true, 'write' => true, 'manage' => true])
        ->and(Gate::forUser($admin)->allows('manageMailboxes'))->toBeTrue()
        ->and(Gate::forUser(user())->allows('manageMailboxes'))->toBeFalse();
});

it('requires a verified email for admins whose model verifies email', function (): void {
    config()->set('mailbox.access.admins', ['admin@example.com']);
    $unverified = VerifiedUser::query()
        ->create(['email' => 'admin@example.com', 'name' => 'Admin']);
    $verified = VerifiedUser::query()
        ->create(['email' => 'admin@example.com', 'email_verified_at' => now(), 'name' => 'Admin']);

    expect(Gate::forUser($unverified)->allows('manageMailboxes'))->toBeFalse()
        ->and(Gate::forUser($unverified)->allows('accessMailbox', mailbox()))->toBeFalse()
        ->and(Gate::forUser($verified)->allows('manageMailboxes'))->toBeTrue();
});

it('gives every user write but not manage in open mode', function (): void {
    config()->set('mailbox.access.mode', 'open');

    expect(abilities(user(), mailbox()))->toBe(['read' => true, 'write' => true, 'manage' => false])
        ->and(Gate::forUser(user())->allows('manageMailboxes'))->toBeFalse();
});

it('hides an inactive mailbox from everyone except admins', function (): void {
    config()->set('mailbox.access.admins', ['admin@example.com']);
    config()->set('mailbox.access.mode', 'open');
    $mailbox = mailbox(['active' => false]);
    $manager = user();
    grant($mailbox, $manager, AccessLevel::Manage);

    expect(abilities($manager, $mailbox)['read'])->toBeFalse()
        ->and(abilities(user(), $mailbox)['read'])->toBeFalse()
        ->and(abilities(user(['email' => 'admin@example.com']), $mailbox)['manage'])->toBeTrue();
});

it('leaves abilities the host app defined itself alone', function (): void {
    Gate::define('accessMailbox', fn (): bool => true);

    MailboxServiceProvider::defineAbilities();

    expect(Gate::forUser(user())->allows('accessMailbox', mailbox()))->toBeTrue()
        ->and(Gate::forUser(user())->allows('workInMailbox', mailbox()))->toBeFalse();
});

it('allows the mailbox menu for users with access to any active mailbox', function (): void {
    config()->set('mailbox.access.admins', ['admin@example.com']);
    $reader = user();
    grant(mailbox(), $reader, AccessLevel::Read);
    $inactiveManager = user();
    grant(mailbox(['active' => false, 'name' => 'Old']), $inactiveManager, AccessLevel::Manage);

    expect(Gate::forUser($reader)->allows('accessAnyMailbox'))->toBeTrue()
        ->and(Gate::forUser($inactiveManager)->allows('accessAnyMailbox'))->toBeFalse()
        ->and(Gate::forUser(user())->allows('accessAnyMailbox'))->toBeFalse()
        ->and(Gate::forUser(user(['email' => 'admin@example.com']))->allows('accessAnyMailbox'))->toBeTrue()
        ->and(Gate::forUser(null)->allows('accessAnyMailbox'))->toBeFalse();
});
