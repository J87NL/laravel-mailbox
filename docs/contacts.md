# Address book & suggestions

## Address book

Each mailbox has its own address book in `mailbox_contacts`, with the model `J87NL\Mailbox\Models\MailboxContact`.

| Column         | Notes                                              |
|----------------|----------------------------------------------------|
| `name`         | Nullable                                           |
| `email`        | Stored trimmed and lowercased, unique per mailbox  |
| `organisation` | Nullable                                           |
| `notes`        | Nullable                                           |

```php
$contact->label(); // 'Jane Doe <jane@example.org>', or just the email without a name
$mailbox->contacts;
```

Add an address once per mailbox with `AddContact`. An existing address is returned unchanged:

```php
use J87NL\Mailbox\Actions\AddContact;

$contact = app(AddContact::class)->handle($mailbox, 'Jane@Example.org', 'Jane Doe');
```

The [ContactForm](forms.md#contactform) offers create and edit forms for a contact.

## Recipient suggestions

`SuggestAddresses` returns suggestions for a recipient field:

```php
use J87NL\Mailbox\Actions\SuggestAddresses;

$suggestions = app(SuggestAddresses::class)->handle($mailbox, 'jan', limit: 10);
// [['value' => 'jane@example.org', 'label' => 'Jane Doe <jane@example.org>'], ...]
```

`handle(Mailbox $mailbox, string $query, int $limit = 10): array`

The list holds, in this order and without duplicate addresses:

1. **Address book contacts** whose email, name or organisation contains the query.
2. **Your providers**: every class in `mailbox.contacts.providers`.
3. **Seen addresses**: the `From`, `To` and `Cc` addresses of the mailbox's most recent `contacts.scanned_messages`
   cached messages (500 by default), except the mailbox's own address. Name and address are matched
   case-insensitively in PHP, also for non-ASCII names.

An empty query returns the first entries of each source. The result is cut to `$limit`.

## Adding a provider

A provider implements `J87NL\Mailbox\Contracts\ProvidesContactSuggestions`. It receives the query lowercased and
trimmed:

```php
<?php

declare(strict_types=1);

namespace App\Mailbox;

use App\Models\Customer;
use J87NL\Mailbox\Contracts\ProvidesContactSuggestions;
use J87NL\Mailbox\Models\Mailbox;

class CustomerSuggestions implements ProvidesContactSuggestions
{
    public function suggestions(Mailbox $mailbox, string $query, int $limit): array
    {
        return Customer::query()
            ->where('email', 'like', "%{$query}%")
            ->limit($limit)
            ->get()
            ->map(fn (Customer $customer): array => [
                'value' => $customer->email,
                'label' => sprintf('%s <%s>', $customer->name, $customer->email),
            ])
            ->all();
    }
}
```

Register it in the config:

```php
'contacts' => [
    'providers' => [
        App\Mailbox\CustomerSuggestions::class,
    ],
],
```

Providers are resolved from the container. Suggestions with an empty `value` are dropped.
