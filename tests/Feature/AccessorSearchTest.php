<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Contracts\ResolvesAccessors;
use J87NL\Mailbox\Forms\AccessForm;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Rules\ExistingAccessor;
use J87NL\Mailbox\Tests\Support\Group;
use J87NL\Mailbox\Tests\Support\User;

function searchAccessors(string $term, int $limit = 20): array
{
    return app(ResolvesAccessors::class)->search($term, $limit);
}

function accessorIsValid(mixed $value): bool
{
    return Validator::make(['accessor' => $value], ['accessor' => ['required', new ExistingAccessor]])->passes();
}

it('finds users by name and email and groups by name, case-insensitively', function (): void {
    $jane = user(['email' => 'jane@example.com', 'name' => 'Jane Doe']);
    $bob = user(['email' => 'bob@JANSEN.example', 'name' => 'Bob']);
    user(['email' => 'carl@example.com', 'name' => 'Carl']);
    $group = Group::query()
        ->create(['name' => 'Janitors']);
    Group::query()
        ->create(['name' => 'Board']);

    expect(searchAccessors('JAN'))->toEqualCanonicalizing([
        ['key' => sprintf('%s:%s', $jane->getMorphClass(), $jane->id), 'label' => 'User: Jane Doe'],
        ['key' => sprintf('%s:%s', $bob->getMorphClass(), $bob->id), 'label' => 'User: Bob'],
        ['key' => sprintf('%s:%s', $group->getMorphClass(), $group->id), 'label' => 'Group: Janitors'],
    ]);
});

it('returns at most the limit', function (): void {
    foreach (range(1, 5) as $number) {
        user(['email' => "member{$number}@example.com", 'name' => "Member {$number}"]);
    }

    expect(searchAccessors('member', 3))->toHaveCount(3);
});

it('treats like wildcards in the term as plain text', function (): void {
    user(['email' => 'percent@example.com', 'name' => '100% Sales']);
    user(['email' => 'other@example.com', 'name' => '1000 Sales']);
    user(['email' => 'under@example.com', 'name' => 'Some_one']);
    user(['email' => 'plain@example.com', 'name' => 'SomeXone']);

    expect(array_column(searchAccessors('0%'), 'label'))->toBe(['User: 100% Sales'])
        ->and(array_column(searchAccessors('e_o'), 'label'))->toBe(['User: Some_one']);
});

it('does not search models that are not configured as accessors', function (): void {
    config()->set('mailbox.access.groups', []);
    Group::query()
        ->create(['name' => 'Janitors']);

    expect(searchAccessors('jan'))->toBe([]);
});

it('accepts an existing user or group as accessor', function (): void {
    $user = user();
    $group = Group::query()
        ->create(['name' => 'Office']);

    expect(accessorIsValid(sprintf('%s:%s', $user->getMorphClass(), $user->id)))->toBeTrue()
        ->and(accessorIsValid(sprintf('%s:%s', $group->getMorphClass(), $group->id)))->toBeTrue();
});

it('rejects an accessor that does not exist or is not assignable', function (mixed $value): void {
    user();

    expect(accessorIsValid($value))->toBeFalse();
})->with([
    'unknown id' => [sprintf('%s:999', User::class)],
    'unconfigured model' => [sprintf('%s:1', Mailbox::class)],
    'unknown class' => ['App\\Nope:1'],
    'no type' => ['1'],
    'empty id' => [sprintf('%s:', User::class)],
    'array' => [['a']],
]);

it('sends a search url instead of the full option list', function (): void {
    user(['name' => 'Jane Doe']);

    $schema = json_decode(json_encode(AccessForm::make('/access', '/accessors')), true);
    $accessor = collect($schema['fields'])->firstWhere('name', 'accessor');

    expect($accessor)->not->toHaveKey('options')
        ->and($accessor['searchUrl'])->toBe('/accessors')
        ->and($accessor['minSearchLength'])->toBe(2)
        ->and(json_encode($schema))->not->toContain('Jane Doe');
});

it('validates the accessor of the access form against the resolver', function (): void {
    $user = user();

    $data = (new AccessForm('', ''))->validate(Request::create('/', 'POST', [
        'accessor' => sprintf('%s:%s', $user->getMorphClass(), $user->id),
        'level' => 'write',
    ]));

    expect($data['level'])->toBe('write')
        ->and(fn () => (new AccessForm('', ''))->validate(Request::create('/', 'POST', [
            'accessor' => sprintf('%s:999', $user->getMorphClass()),
            'level' => 'write',
        ])))->toThrow(ValidationException::class);
});

it('rejects the class name of a model that has a morph alias', function (): void {
    Relation::morphMap(['member' => User::class]);
    $user = user();

    try {
        expect(accessorIsValid(sprintf('member:%s', $user->id)))->toBeTrue()
            ->and(accessorIsValid(sprintf('%s:%s', User::class, $user->id)))->toBeFalse();
    } finally {
        Relation::morphMap([], false);
    }
});

it('does not list every accessor when a source has no search columns', function (): void {
    config()->set('mailbox.access.user_search', []);
    config()->set('mailbox.access.groups.0.search', []);
    user(['email' => 'carl@example.com', 'name' => 'Carl']);
    Group::query()
        ->create(['name' => 'Office']);

    expect(searchAccessors('zz'))->toBe([])
        ->and(array_column(searchAccessors('carl@'), 'label'))->toBe(['User: Carl']);
});
