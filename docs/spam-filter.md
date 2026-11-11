# Spam filter

A hook that lets your app judge incoming mail and move clear spam to the junk folder. The package brings the plumbing:
the per-mailbox mode, the stored verdicts, the allowlist and the moves. The judging itself is up to your app.

The filter errs on the safe side. One spam mail too many in the inbox is better than one real mail in the junk folder:

- it never deletes mail, it only moves it to the junk folder with [`MarkJunk`](message-actions.md#spam);
- "Not spam" always brings a message back and keeps its sender out of the filter from then on;
- without a verdict, or when the classifier fails, nothing moves.

## Modes

Every mailbox has a `spam_filter_mode` (`J87NL\Mailbox\Enums\SpamFilterMode`), `off` by default:

| Mode      | What happens to new mail                                                                          |
|-----------|---------------------------------------------------------------------------------------------------|
| `off`     | Nothing. No job is queued and the sync does no extra work                                         |
| `measure` | The score and reason are stored, nothing else                                                     |
| `suggest` | As `measure`, and a score from `spam.suggest_threshold` on sets `spam_status` to `suggested`      |
| `auto`    | As `suggest`, and a score from `spam.move_threshold` on moves the message to the junk folder      |

Start with `measure` to see what the classifier would do, then move on to `suggest` and `auto`.

## How a message is checked

The package listens to [`MessageReceived`](events.md#messagereceived). For a mailbox whose mode is not `off` and a
message without `spam_checked_at`, it queues a `J87NL\Mailbox\Jobs\CheckSpamJob` on `spam.queue`. The job runs
`J87NL\Mailbox\Spam\CheckSpam`:

1. A sender on the [allowlist](#the-allowlist) is not classified: `spam_checked_at` is set, nothing else.
2. Otherwise your [classifier](#writing-a-classifier) is asked for a verdict.
3. No verdict, or the classifier throws: the exception is reported, `spam_checked_at` is set and nothing else happens.
   The job does not fail and is not retried, so a broken classifier never moves mail and never loops.
4. With a score, `spam_score` and `spam_reason` are stored and the mode decides the rest:

| Score                                   | `measure` | `suggest`   | `auto`                         |
|-----------------------------------------|-----------|-------------|--------------------------------|
| below `spam.suggest_threshold` (`0.80`) | `null`    | `null`      | `null`                         |
| from `spam.suggest_threshold`           | `null`    | `suggested` | `suggested`                    |
| from `spam.move_threshold` (`0.95`)     | `null`    | `suggested` | moved to the junk folder       |

A move goes through `MarkJunk::junk()` without a user and writes the audit action `spam_moved` with the `score` and
`reason` as meta. When the move throws, the error is reported and the message stays in the inbox as `suggested`.

Mail the sync announces nothing for, such as mail in the junk folder, a first sync or `FetchOlderMessages`, is never
checked. The job runs once per message: a message with `spam_checked_at` is skipped.

## Stored on the message

| Column            | Notes                                                                                   |
|-------------------|-----------------------------------------------------------------------------------------|
| `spam_score`      | The score from `0` to `1`, `null` without a verdict                                     |
| `spam_reason`     | The reason the classifier gave                                                          |
| `spam_status`     | A `SpamStatus` enum: `suggested`, `moved` or `released`, or `null`                      |
| `spam_checked_at` | When the filter looked at the message; also set when it skipped or found no verdict     |

## Not spam

The filter learns from the users through [`MarkJunk`](message-actions.md#spam), for a mailbox whose mode is not `off`
and only when a user is passed:

- `notJunk()` adds the address of every sender to the allowlist of that mailbox (source `not_junk`) and sets
  `spam_status` to `released`. From then on their mail skips the classifier, and their mail in the junk folder is moved
  back (see [Mail in the junk folder](#mail-in-the-junk-folder)).
- `junk()` clears a `suggested` status. The sender stays on the allowlist when it was there; remove it with
  `SpamAllowlist::remove()` when that is what your UI offers.

Calls without a user, such as the filter's own moves, teach nothing.

## The allowlist

Senders on the allowlist are never classified, and their mail is moved out of the junk folder (see
[Mail in the junk folder](#mail-in-the-junk-folder)). An entry is a lowercased email address or a domain, for one
mailbox or, with a `null` `mailbox_id`, for every mailbox. A domain matches only that domain, not its subdomains.

`J87NL\Mailbox\Spam\SpamAllowlist` manages it:

```php
use J87NL\Mailbox\Enums\SpamAllowlistSource;
use J87NL\Mailbox\Spam\SpamAllowlist;

$allowlist = app(SpamAllowlist::class);

$allowlist->add($mailbox, 'boss@example.org');                          // source manual
$allowlist->add(null, 'example.org', SpamAllowlistSource::Default);     // every mailbox, the whole domain
$allowlist->remove($mailbox, 'boss@example.org');                       // true when an entry was removed

$allowlist->matches($mailbox, 'Boss@Example.org');                      // true
$allowlist->allowedSenders($mailbox, collect(['a@example.org', 'b@spam.example'])); // the allowed ones, one query
```

Patterns are trimmed, lowercased and lose a leading `@`, so `@Example.org` and `example.org` are the same entry.
`add()` keeps an existing entry and its source. The `source` tells where an entry came from:

| Source     | Added by                                                                                   |
|------------|--------------------------------------------------------------------------------------------|
| `manual`   | your app, the default of `add()`                                                           |
| `not_junk` | the package, when a user marks a message as not spam (see [Not spam](#not-spam))           |
| `default`  | your app, for entries it ships itself, for example a seeder with partner domains           |

## Writing a classifier

Bind your own `J87NL\Mailbox\Contracts\ClassifiesSpam` in a service provider. It gets the cached message row; the
body is not fetched yet, so call `FetchMessageBody` when you need it.

```php
<?php

declare(strict_types=1);

namespace App\Mailbox;

use App\Services\SpamService;
use J87NL\Mailbox\Contracts\ClassifiesSpam;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Spam\SpamVerdict;

class SpamClassifier implements ClassifiesSpam
{
    public function __construct(
        private readonly SpamService $spam,
    ) {}

    public function classify(MailboxMessage $message): SpamVerdict
    {
        $result = $this->spam->check((string) $message->from_email, (string) $message->subject);

        if (is_null($result)) {
            return SpamVerdict::none();
        }

        return new SpamVerdict($result->probability, $result->explanation);
    }
}
```

```php
use J87NL\Mailbox\Contracts\ClassifiesSpam;

$this->app->bind(ClassifiesSpam::class, App\Mailbox\SpamClassifier::class);
```

- The score runs from `0` (surely not spam) to `1` (surely spam). A score outside that range throws an
  `InvalidArgumentException`, which the filter handles like any other failure.
- Return `SpamVerdict::none()` when you have no opinion. The message is then only marked as checked.
- Without a binding the default `NullSpamClassifier` returns `SpamVerdict::none()` for every message, so the filter
  does nothing in any mode.
- The text of a mail is written by its sender. When the classifier is an AI model, treat the mail as data and give the
  model no tools.

## Testing

`J87NL\Mailbox\Testing\FakeSpamClassifier` replaces your classifier in tests, see
[Testing](testing.md#spam-classifier).
