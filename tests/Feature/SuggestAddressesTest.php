<?php

declare(strict_types=1);

use J87NL\Mailbox\Actions\SuggestAddresses;
use J87NL\Mailbox\Contracts\ProvidesContactSuggestions;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxContact;

it('suggests distinct addresses seen in the mailbox, filtered and limited', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    storedMessage($inbox, 1, [
        'cc' => [['email' => 'Anna@Example.org', 'name' => '']],
        'from_email' => 'anna@example.org',
        'from_name' => 'Anna de Vries',
        'sent_at' => now(),
        'to' => [['email' => 'team@example.com', 'name' => 'Team']],
    ]);
    storedMessage($inbox, 2, [
        'from_email' => 'bob@example.org',
        'from_name' => 'Bob',
        'sent_at' => now()->subDay(),
        'to' => [['email' => 'annabel@example.net', 'name' => '']],
    ]);
    storedMessage(folder(mailbox(['name' => 'Other'])), 3, [
        'from_email' => 'anne@elsewhere.org',
    ]);

    $suggest = app(SuggestAddresses::class);

    expect($suggest->handle($mailbox, 'ann'))->toBe([
        ['label' => 'Anna de Vries <anna@example.org>', 'value' => 'anna@example.org'],
        ['label' => 'annabel@example.net', 'value' => 'annabel@example.net'],
    ])
        ->and($suggest->handle($mailbox, '', 2))->toHaveCount(2)
        ->and(collect($suggest->handle($mailbox, ''))->pluck('value')->all())->not->toContain('team@example.com');
});

it('lists address book contacts first, then host providers, then seen addresses, without duplicates', function (): void {
    config()->set('mailbox.contacts.providers', [RenterSuggestions::class]);
    $mailbox = mailbox();
    MailboxContact::query()
        ->create([
            'email' => 'Anna@Example.org',
            'mailbox_id' => $mailbox->id,
            'name' => 'Anna Contact',
        ]);
    storedMessage(folder($mailbox), 1, [
        'from_email' => 'anna@example.org',
        'from_name' => 'Anna Seen',
        'to' => [['email' => 'annie@seen.org', 'name' => '']],
    ]);

    expect(app(SuggestAddresses::class)->handle($mailbox, 'ann'))->toBe([
        ['label' => 'Anna Contact <anna@example.org>', 'value' => 'anna@example.org'],
        ['label' => 'Annemarie Renter <annemarie@renter.org>', 'value' => 'annemarie@renter.org'],
        ['label' => 'annie@seen.org', 'value' => 'annie@seen.org'],
    ])
        ->and(app(SuggestAddresses::class)->handle($mailbox, 'ann', 2))->toHaveCount(2);
});

class RenterSuggestions implements ProvidesContactSuggestions
{
    public function suggestions(Mailbox $mailbox, string $query, int $limit): array
    {
        return [
            ['label' => 'Anna Duplicate <ANNA@example.org>', 'value' => 'ANNA@example.org'],
            ['label' => 'Annemarie Renter <annemarie@renter.org>', 'value' => 'annemarie@renter.org'],
        ];
    }
}
