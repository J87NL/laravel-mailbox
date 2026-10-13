<?php

declare(strict_types=1);

use J87NL\Mailbox\Actions\SuggestAddresses;
use J87NL\Mailbox\Contracts\ProvidesContactSuggestions;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxContact;

/**
 * @return array{email: string, name: string}
 */
function address(string $email, string $name = ''): array
{
    return [
        'email' => $email,
        'name' => $name,
    ];
}

/**
 * @return array{label: string, value: string}
 */
function suggestion(string $label, string $value): array
{
    return [
        'label' => $label,
        'value' => $value,
    ];
}

it('suggests distinct addresses seen in the mailbox, filtered and limited', function (): void {
    $mailbox = mailbox();
    $inbox = folder($mailbox);
    storedMessage($inbox, 1, [
        'cc' => [
            address('Anna@Example.org'),
        ],
        'from_email' => 'anna@example.org',
        'from_name' => 'Anna de Vries',
        'sent_at' => now(),
        'to' => [
            address('team@example.com', 'Team'),
        ],
    ]);
    storedMessage($inbox, 2, [
        'from_email' => 'bob@example.org',
        'from_name' => 'Bob',
        'sent_at' => now()->subDay(),
        'to' => [
            address('annabel@example.net'),
        ],
    ]);
    storedMessage(folder(mailbox(['name' => 'Other'])), 3, [
        'from_email' => 'anne@elsewhere.org',
    ]);

    $suggest = app(SuggestAddresses::class);

    expect($suggest->handle($mailbox, 'ann'))->toBe([
        suggestion('Anna de Vries <anna@example.org>', 'anna@example.org'),
        suggestion('annabel@example.net', 'annabel@example.net'),
    ])
        ->and($suggest->handle($mailbox, '', 2))->toHaveCount(2)
        ->and(collect($suggest->handle($mailbox, ''))->pluck('value')->all())->not->toContain('team@example.com');
});

it('finds names regardless of case and accents in the stored JSON', function (string $query, string $expected): void {
    $mailbox = mailbox();
    storedMessage(folder($mailbox), 1, [
        'from_email' => 'info@example.org',
        'from_name' => 'INFO DESK',
        'to' => [
            address('jose@example.org', 'José Álvarez'),
        ],
    ]);

    expect(collect(app(SuggestAddresses::class)->handle($mailbox, $query))->pluck('value')->all())->toBe([
        $expected,
    ]);
})->with([
    'uppercase name, lowercase query' => [
        'info desk',
        'info@example.org',
    ],
    'non-ASCII name' => [
        'josé',
        'jose@example.org',
    ],
    'non-ASCII uppercase query' => [
        'ÁLVAREZ',
        'jose@example.org',
    ],
]);

it('lists address book contacts first, then host providers, then seen addresses, without duplicates', function (): void {
    config()->set('mailbox.contacts.providers', [
        RenterSuggestions::class,
    ]);
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
        'to' => [
            address('annie@seen.org'),
        ],
    ]);

    expect(app(SuggestAddresses::class)->handle($mailbox, 'ann'))->toBe([
        suggestion('Anna Contact <anna@example.org>', 'anna@example.org'),
        suggestion('Annemarie Renter <annemarie@renter.org>', 'annemarie@renter.org'),
        suggestion('annie@seen.org', 'annie@seen.org'),
    ])
        ->and(app(SuggestAddresses::class)->handle($mailbox, 'ann', 2))->toHaveCount(2);
});

class RenterSuggestions implements ProvidesContactSuggestions
{
    public function suggestions(Mailbox $mailbox, string $query, int $limit): array
    {
        return [
            suggestion('Anna Duplicate <ANNA@example.org>', 'ANNA@example.org'),
            suggestion('Annemarie Renter <annemarie@renter.org>', 'annemarie@renter.org'),
        ];
    }
}
