<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use J87NL\Mailbox\Contracts\ResolvesAccessors;
use J87NL\Mailbox\Enums\AccessLevel;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAccess;
use J87NL\Mailbox\Rules\ExistingAccessor;
use J87NL\Mailbox\Tests\Support\Group;
use J87NL\Mailbox\Tests\Support\WithoutClients;

function accessorKey(Model $model): string
{
    return sprintf('%s:%s', $model->getMorphClass(), $model->getKey());
}

function grantAccessor(Mailbox $mailbox, Model $accessor): void
{
    MailboxAccess::query()
        ->create([
            'accessor_id' => $accessor->getKey(),
            'accessor_type' => $accessor->getMorphClass(),
            'level' => AccessLevel::Write,
            'mailbox_id' => $mailbox->id,
        ]);
}

beforeEach(function (): void {
    config()->set('mailbox.access.user_query', WithoutClients::class);
    config()->set('mailbox.access.groups.0.query', fn (Builder $query): Builder => $query->where('name', '!=', 'Clients'));

    $this->staff = user(['email' => 'jane@example.com', 'name' => 'Jane Staff']);
    $this->client = user(['email' => 'jack@client.example', 'name' => 'Jack Client']);
    $this->office = Group::query()
        ->create(['name' => 'Office']);
    $this->clients = Group::query()
        ->create(['name' => 'Clients']);
});

it('leaves filtered users and groups out of the search', function (): void {
    $labels = array_column(app(ResolvesAccessors::class)->search('e', 20), 'label');

    expect($labels)->toEqualCanonicalizing([
        'User: Jane Staff',
        'Group: Office',
    ]);
});

it('leaves filtered users and groups out of the assignable list', function (): void {
    expect(array_column(app(ResolvesAccessors::class)->assignable(), 'key'))->toEqualCanonicalizing([
        accessorKey($this->staff),
        accessorKey($this->office),
    ]);
});

it('does not label or validate a filtered accessor', function (): void {
    $labels = app(ResolvesAccessors::class)->labels([
        ['id' => $this->staff->id, 'type' => $this->staff->getMorphClass()],
        ['id' => $this->client->id, 'type' => $this->client->getMorphClass()],
        ['id' => $this->clients->id, 'type' => $this->clients->getMorphClass()],
    ]);

    $passes = fn (Model $model): bool => Validator::make(
        ['accessor' => accessorKey($model)],
        ['accessor' => [new ExistingAccessor]],
    )->passes();

    expect(array_keys($labels))->toBe([accessorKey($this->staff)])
        ->and($passes($this->staff))->toBeTrue()
        ->and($passes($this->office))->toBeTrue()
        ->and($passes($this->client))->toBeFalse()
        ->and($passes($this->clients))->toBeFalse();
});

it('gives a filtered user no level, not even through an allowed group', function (): void {
    $mailbox = mailbox();
    $this->client->groups()->attach([$this->office->id, $this->clients->id]);
    grantAccessor($mailbox, $this->client);
    grantAccessor($mailbox, $this->office);

    expect(app(ResolvesAccessors::class)->accessorsFor($this->client))->toBe([])
        ->and(Gate::forUser($this->client)->allows('accessMailbox', $mailbox))->toBeFalse()
        ->and(Gate::forUser($this->client)->allows('accessAnyMailbox'))->toBeFalse();
});

it('keeps the access of an allowed user through an allowed group', function (): void {
    $mailbox = mailbox();
    $this->staff->groups()->attach([$this->office->id, $this->clients->id]);
    grantAccessor($mailbox, $this->office);

    expect(app(ResolvesAccessors::class)->accessorsFor($this->staff))->toBe([
        ['id' => $this->staff->id, 'type' => $this->staff->getMorphClass()],
        ['id' => $this->office->id, 'type' => $this->office->getMorphClass()],
    ])
        ->and(Gate::forUser($this->staff)->allows('workInMailbox', $mailbox))->toBeTrue();
});

it('accepts a callable array as query', function (): void {
    config()->set('mailbox.access.user_query', [new WithoutClients, '__invoke']);

    expect(array_column(app(ResolvesAccessors::class)->search('jack', 20), 'label'))->toBe([]);
});

it('rejects a query that is not callable', function (): void {
    config()->set('mailbox.access.user_query', 'App\\Nope');

    app(ResolvesAccessors::class)->search('jane', 20);
})->throws(InvalidArgumentException::class);

it('keeps a query with an or-condition from widening the key and search constraints', function (): void {
    config()->set('mailbox.access.user_query', fn (Builder $query): Builder => $query->where('email', 'jane@example.com')->orWhere('name', 'Nobody'));
    $mailbox = mailbox();
    grantAccessor($mailbox, $this->client);

    expect(array_column(app(ResolvesAccessors::class)->search('jack', 20), 'label'))->toBe([])
        ->and(app(ResolvesAccessors::class)->labels([['id' => $this->client->id, 'type' => $this->client->getMorphClass()]]))->toBe([])
        ->and(Gate::forUser($this->client)->allows('accessMailbox', $mailbox))->toBeFalse();
});
