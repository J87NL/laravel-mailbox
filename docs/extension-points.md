# Extension points

The package has four contracts in `J87NL\Mailbox\Contracts`. Each one has a default or can be left out, except
`ProvidesAccessToken`, which mailboxes with OAuth authentication require.

| Contract                     | How to register                      | Default                     |
|------------------------------|--------------------------------------|-----------------------------|
| `ResolvesAccessors`          | `mailbox.access.resolver`            | `RelationAccessorResolver`  |
| `ProvidesContactSuggestions` | `mailbox.contacts.providers`         | none                        |
| `ProvidesAccessToken`        | `mailbox.oauth_token_provider`       | none                        |
| `ResolvesInlineImageUrls`    | container binding                    | `NoInlineImageUrls`         |

## ResolvesAccessors

Decides which accessors (the user and their groups) a user is, and which accessors can be granted access.

```php
interface ResolvesAccessors
{
    /**
     * @return array<int, array{type: string, id: int|string}>
     */
    public function accessorsFor(Authenticatable $user): array;

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function assignable(): array;

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function search(string $term, int $limit): array;

    /**
     * @param  array<int, array{type: string, id: int|string}>  $accessors
     * @return array<string, string>
     */
    public function labels(array $accessors): array;
}
```

- `accessorsFor()` returns the morph type and id of the user and every group they belong to. The access rows of these
  accessors decide the user's level.
- `assignable()` returns every accessor that can be granted access, for `mailbox:grant`. The `key` is `type:id`.
- `search()` returns at most `$limit` of those accessors that match `$term`, for the search endpoint of the access form.
  Never return an accessor that `assignable()` would not list.
- `labels()` returns a label per `type:id` key, for showing existing access rows. `ExistingAccessor` also uses it: a key
  without a label is not a valid accessor.

The default `RelationAccessorResolver` reads the groups from `mailbox.access.groups`, see
[Access control](access.md#users-and-groups). To only keep certain users or groups out, configure `access.user_query`
or a group's `query` instead of writing your own resolver, see
[Keeping users and groups out](access.md#keeping-users-and-groups-out). `MailboxAccessResolver` applies
`access.user_query` itself before it asks your resolver, so a filtered user gets no access whichever resolver you bind. Set your own class in the config:

```php
'access' => [
    'resolver' => App\Mailbox\TeamAccessors::class,
],
```

It is resolved from the container once per request (a scoped binding).

## ProvidesContactSuggestions

Adds recipient suggestions from your own data:

```php
interface ProvidesContactSuggestions
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function suggestions(Mailbox $mailbox, string $query, int $limit): array;
}
```

See [Address book & suggestions](contacts.md#adding-a-provider).

## ProvidesAccessToken

Required for mailboxes with `authentication` set to `oauth`. It returns a current OAuth access token, used as the
XOAUTH2 password for IMAP and SMTP:

```php
interface ProvidesAccessToken
{
    public function accessToken(Mailbox $mailbox): string;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Mailbox;

use App\Models\OAuthToken;
use J87NL\Mailbox\Contracts\ProvidesAccessToken;
use J87NL\Mailbox\Models\Mailbox;

class MailboxTokens implements ProvidesAccessToken
{
    public function accessToken(Mailbox $mailbox): string
    {
        return OAuthToken::query()
            ->where('email', $mailbox->email)
            ->firstOrFail()
            ->freshAccessToken();
    }
}
```

```php
'oauth_token_provider' => App\Mailbox\MailboxTokens::class,
```

Storing and refreshing tokens is up to your app; the package has no OAuth onboarding flow. Without a provider,
connecting an OAuth mailbox throws a `MailboxException`.

## ResolvesInlineImageUrls

Tells `QuotedImages` which image URLs are signed inline-image URLs of your app, so quoted inline images can be embedded
again when sending, and so those URLs never leave the app. See
[Sending mail](sending.md#quoting-and-inline-images).

```php
interface ResolvesInlineImageUrls
{
    /**
     * @return array{message_id: int, content_id: string}|null
     */
    public function parse(string $url): ?array;

    public function urlPrefix(): ?string;
}
```

- `parse()` returns the `MailboxMessage` id and the Content-ID for a valid signed inline URL, or `null` for any other
  URL. Check the signature here.
- `urlPrefix()` returns the start of every inline URL. Every occurrence is removed from outgoing mail.

The default `NoInlineImageUrls` returns `null` from both. Bind your own in a service provider:

```php
use J87NL\Mailbox\Contracts\ResolvesInlineImageUrls;

$this->app->bind(ResolvesInlineImageUrls::class, App\Mailbox\InlineImageUrls::class);
```

The package binds its default with `bindIf()`, so a binding of your own wins.

The UI package binds its own implementation, `J87NL\VueMailbox\Support\InlineUrls`, for its signed inline route. See
its [inline images](https://github.com/J87NL/laravel-vue-mailbox/blob/main/docs/inline-images.md) docs.

## Replacing the connector

All IMAP and SMTP traffic goes through `J87NL\Mailbox\Connection\MailboxConnector`, bound as a scoped singleton. The
[test fake](testing.md) replaces it with `FakeConnector`.
