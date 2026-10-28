# Access control

Access is checked with Laravel Gate abilities. The package defines them after the application has booted, and only when
your app has not defined an ability with the same name.

## Abilities

| Ability            | Arguments          | Allows                                                                                                                           |
|--------------------|--------------------|----------------------------------------------------------------------------------------------------------------------------------|
| `accessMailbox`    | `Mailbox $mailbox` | Read: view folders, messages and attachments                                                                                     |
| `workInMailbox`    | `Mailbox $mailbox` | Write: also send, reply, forward, move, archive, delete, flag, mark read or unread, create folders, manage address book contacts |
| `manageMailbox`    | `Mailbox $mailbox` | Manage: also edit the mailbox settings and its access list                                                                       |
| `accessAnyMailbox` | none               | True for admins and for users with at least read on an active mailbox                                                            |
| `manageMailboxes`  | none               | Global: create and delete mailboxes. True for admins only                                                                        |

A user that [`access.user_query`](#keeping-users-and-groups-out) filters out passes none of these abilities, also when
their email is in `MAILBOX_ADMINS` and in open mode.

```php
use Illuminate\Support\Facades\Gate;

Gate::authorize('accessMailbox', $mailbox);

if ($request->user()->can('workInMailbox', $mailbox)) {
    // ...
}
```

Use `accessAnyMailbox` to decide whether to show a mailbox menu link. Guests are always denied. When your app defines
`manageMailboxes` itself, a user can pass it without having access to any mailbox, and then fails `accessAnyMailbox`.
Such a user must still see the menu link to create the first mailbox:

- With the UI package, gate the menu link on `@canany(['accessAnyMailbox', 'manageAnyMailbox'])`. `manageAnyMailbox`
  is defined by the UI package, not by this one: it passes with `manageMailboxes` or with `manageMailbox` on at least
  one mailbox. See its [menu gate](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/navigation.md#menu-gate).
- Without the UI package, gate the link to your own mailbox pages on `@canany(['accessAnyMailbox', 'manageMailboxes'])`.
  This package does not define `manageAnyMailbox`.

When only `MAILBOX_ADMINS` create mailboxes, `accessAnyMailbox` alone is enough, because admins pass it.

The package checks no abilities itself, except `SaveMailbox` for OAuth server changes (see
[Security](security.md#saving-a-mailbox)). Call `Gate::authorize()` in your controllers before calling an action.

The UI package checks these abilities on every route it registers, except the signed inline image route and the settings
list. See its [routes and authorization](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/routes.md).

## Levels

`J87NL\Mailbox\Enums\AccessLevel` has three cases, from low to high: `Read`, `Write` and `Manage`. A higher level
includes the lower ones.

```php
use J87NL\Mailbox\Enums\AccessLevel;

AccessLevel::Write->allows(AccessLevel::Read);            // true
AccessLevel::highest([AccessLevel::Read, AccessLevel::Manage]); // AccessLevel::Manage
AccessLevel::Manage->label();                              // 'Manage'
```

## How the level is decided

`J87NL\Mailbox\Access\MailboxAccessResolver` decides the level of a user for a mailbox:

1. A user that **`access.user_query`** filters out gets no level on any mailbox, whatever comes below.
2. An **inactive** mailbox is only accessible to admins.
3. **Admins** get `Manage` on every mailbox.
4. In **open mode** every logged-in user gets `Write` on every active mailbox.
5. Otherwise the **access rows** decide. The highest level over the user's own row and the rows of all their groups
   wins. No row means no access.

The resolver caches levels per request. It is bound as a scoped singleton. It runs `access.user_query` at most once per
user per request (`MailboxAccessResolver::admits()`), whichever [accessor resolver](#users-and-groups) is bound, and
`RelationAccessorResolver` reuses that answer. The query only applies to users of `mailbox.user_model`: another
authenticatable, for example of a second guard, is not filtered.

### Admins

Admins are the emails in `mailbox.access.admins` (`MAILBOX_ADMINS`), compared case-insensitively with the `email`
attribute of the user. When the user model implements `MustVerifyEmail`, the email must be verified. A user that
`access.user_query` filters out is no admin, even with their email in `MAILBOX_ADMINS`.

Admins are the bootstrap: without one, nobody can create a mailbox through a UI. The `mailbox:add` command always works.

### Open mode

```dotenv
MAILBOX_ACCESS_MODE=open
```

Every logged-in user gets `Write` on every active mailbox, without access rows. Only admins get `Manage`. A user that
`access.user_query` filters out gets nothing. Useful for small apps where everybody shares one mailbox. The default is
`restricted`.

## Users and groups

An access row belongs to an accessor: a user or a group model. Groups are configured in `mailbox.access.groups`:

```php
'groups' => [
    [
        'model' => Spatie\Permission\Models\Role::class,
        'relation' => 'roles',
        'label' => 'name',
        'prefix' => 'Role',
    ],
],
```

The default resolver, `J87NL\Mailbox\Access\RelationAccessorResolver`, reads the user itself plus every model in the
`relation` of the user. A group whose relation method does not exist on the user is skipped.

For `mailbox:grant` it lists every user (labelled with `access.user_label`) and every model of each group (labelled
with the group's `label`), as `prefix: label`. Each option has the key `morph-class:id`. Users get the prefix
`mailbox::mailbox.accessor_user` ("User", "Gebruiker"). A group's `prefix` is a plain string or a translation key: when
a translation exists for the current locale, for example `'prefix' => 'mailbox.role'` with `'role' => 'Rol'` in
`lang/nl/mailbox.php`, the label shows it. Like `__()`, a plain string such as `Role` is also translated when the app
has a JSON translation for it. Otherwise the string is shown as it is. Without `prefix` the class basename of
the model is used. A key keeps `config:cache` working, unlike a closure or a `__()` call in the config file.

For the access form it searches the same models instead, case-insensitively: users on `access.user_search` (default
`user_label` and `email`), groups on their `search` columns (default their `label`). A search returns at most the
requested number of matches, sorted by label. See [AccessForm](forms.md#accessform).

### Keeping users and groups out

Not every user or group should be offered in the access form, for example customers who log in to the same app. Narrow
the candidates with `access.user_query` and a `query` per group:

```php
'access' => [
    'user_query' => App\Mailbox\StaffOnly::class,
    'groups' => [
        [
            'model' => Spatie\Permission\Models\Role::class,
            'relation' => 'roles',
            'query' => [App\Mailbox\StaffOnly::class, 'roles'],
        ],
    ],
],
```

```php
namespace App\Mailbox;

use Illuminate\Database\Eloquent\Builder;

class StaffOnly
{
    public function __invoke(Builder $query): void
    {
        $query->whereDoesntHave('roles', fn (Builder $roles): Builder => $roles->where('name', 'client'));
    }

    public static function roles(Builder $query): void
    {
        $query->where('name', '!=', 'client');
    }
}
```

The value is an invokable class name (resolved from the container) or a callable. It receives the Eloquent `Builder` of
the user model or the group model and adds constraints. A closure works too, but breaks `config:cache`.

`RelationAccessorResolver` applies it everywhere it reads candidates: the search of the access form,
`assignable()` for `mailbox:grant`, `labels()` and therefore `ExistingAccessor`, and `accessorsFor()`. So a filtered
user or group cannot be found or granted, and an access row it already has is shown without a label. A filtered user
gets no level at all, not even through an allowed group, and an allowed user gets no level from a filtered group. The
user filter also applies to admins and to open mode: a filtered user is no admin, gets nothing in open mode and fails
`accessAnyMailbox`, `manageMailboxes` and every mailbox ability. The app needs no `Gate::before` of its own for this.
An ability your app defines itself, such as its own `manageMailboxes`, replaces the package one and must apply the
filter itself, for example with `J87NL\Mailbox\Access\AccessQuery::admitsUser($user)`. The UI's `manageAnyMailbox`
passes with `manageMailboxes`, so it follows your definition.

The [MCP tools](mcp.md) check the same abilities, so a filtered user cannot use them either.

Another group concept? Bind your own `ResolvesAccessors`, see
[Extension points](extension-points.md#resolvesaccessors).

## Granting and revoking

From the command line:

```bash
php artisan mailbox:grant 3
```

From code, with `J87NL\Mailbox\Actions\GrantAccess`:

```php
use J87NL\Mailbox\Actions\GrantAccess;
use J87NL\Mailbox\Enums\AccessLevel;

$access = app(GrantAccess::class)->handle(
    $mailbox,
    $user->getMorphClass(),
    $user->getKey(),
    AccessLevel::Write,
    $request->user(),
);

app(GrantAccess::class)->revoke($access, $request->user());
```

`handle()` creates or updates the row for that accessor. Both methods write an `access_granted` or `access_revoked`
audit entry and flush the resolver cache.

The last `Manage` row of a mailbox cannot be revoked or lowered. The attempt throws a `ValidationException` on the key
`accessor`.

The [AccessForm](forms.md#accessform) offers the accessor and level fields for a UI.

## Overriding abilities

Define an ability yourself, for example in a service provider's `boot()` method, and the package leaves it alone:

```php
Gate::define('manageMailboxes', fn (User $user): bool => $user->is_super_admin);
```

The definitions are made in the public static method `MailboxServiceProvider::defineAbilities()`, which skips every
ability that is already defined.
