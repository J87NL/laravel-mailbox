# Access control

Access is checked with Laravel Gate abilities. The package defines them after the application has booted, and only when
your app has not defined an ability with the same name.

## Abilities

| Ability            | Arguments            | Allows                                                                 |
|--------------------|----------------------|------------------------------------------------------------------------|
| `accessMailbox`    | `Mailbox $mailbox`   | Read: view folders, messages and attachments                           |
| `workInMailbox`    | `Mailbox $mailbox`   | Write: also send, reply, forward, move, archive, delete, flag, mark read or unread, create folders |
| `manageMailbox`    | `Mailbox $mailbox`   | Manage: also edit the mailbox settings and its access list             |
| `accessAnyMailbox` | none                 | True for admins and for users with at least read on an active mailbox  |
| `manageMailboxes`  | none                 | Global: create and delete mailboxes. True for admins only              |

```php
use Illuminate\Support\Facades\Gate;

Gate::authorize('accessMailbox', $mailbox);

if ($request->user()->can('workInMailbox', $mailbox)) {
    // ...
}
```

Use `accessAnyMailbox` to decide whether to show a mailbox menu link. Guests are always denied.

The package checks no abilities itself, except `SaveMailbox` for OAuth server changes (see
[Security](security.md#saving-a-mailbox)). Call `Gate::authorize()` in your controllers before calling an action.

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

1. An **inactive** mailbox is only accessible to admins.
2. **Admins** get `Manage` on every mailbox.
3. In **open mode** every logged-in user gets `Write` on every active mailbox.
4. Otherwise the **access rows** decide. The highest level over the user's own row and the rows of all their groups
   wins. No row means no access.

The resolver caches levels per request. It is bound as a scoped singleton.

### Admins

Admins are the emails in `mailbox.access.admins` (`MAILBOX_ADMINS`), compared case-insensitively with the `email`
attribute of the user. When the user model implements `MustVerifyEmail`, the email must be verified.

Admins are the bootstrap: without one, nobody can create a mailbox through a UI. The `mailbox:add` command always works.

### Open mode

```dotenv
MAILBOX_ACCESS_MODE=open
```

Every logged-in user gets `Write` on every active mailbox, without access rows. Only admins get `Manage`. Useful for
small apps where everybody shares one mailbox. The default is `restricted`.

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

For the access form and `mailbox:grant` it lists every user (labelled with `access.user_label`) and every model of each
group (labelled with the group's `label`), as `prefix: label`. Each option has the key `morph-class:id`.

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
