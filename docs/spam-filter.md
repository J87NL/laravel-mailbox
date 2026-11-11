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

Set it in the "Spam filter" select of [`MailboxForm`](forms.md#mailboxform), through `SaveMailbox`, or on the model.
`SpamFilterMode::label()` gives the translated label (`mailbox::mailbox.spam_filter_mode_<value>`).

## How a message is checked

The package listens to [`MessageReceived`](events.md#messagereceived). For a mailbox whose mode is not `off` and a
message without `spam_checked_at`, it queues a `J87NL\Mailbox\Jobs\CheckSpamJob` on `spam.queue`. The job is unique
per message for an hour (`ShouldBeUnique`), so a message is never classified or moved twice at the same time. The job runs
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

The job runs later than the sync. A message a user has read, flagged or answered by then (`is_seen`, `is_flagged`,
`is_answered` on the cached row) is never moved in `auto` mode: it gets `suggested` instead.

A threshold that is empty or outside `0` (exclusive) to `1` uses its default, and a `move_threshold` below the
`suggest_threshold` makes both use their defaults, with a warning in the log. An empty env value therefore never moves
every scored mail.

A mailbox gets at most `spam.max_moves_per_hour` (default `20`) moves per hour, counted from its `spam_moved` audit
entries. Above that a message is only `suggested` and a warning is logged, so a classifier that goes wrong cannot empty
the inbox.

A move goes through `MarkJunk::junk()` without a user and writes the audit action `spam_moved` with the `score` and
`reason` as meta. When the move throws, the error is reported and the message stays in the inbox as `suggested`.

Mail the sync announces nothing for, such as mail in the junk folder, a first sync or `FetchOlderMessages`, is never
checked. The job runs once per message: a message with `spam_checked_at` is skipped.

## After the sync

A move gives a message a new row in the target folder (see [Data model](data-model.md#mailboxmessage)), so the
verdict on the old row is gone. For a mailbox whose mode is not `off`, the folder sync hands the messages it stored to
`J87NL\Mailbox\Spam\FilterSyncedMessages`, which reads their audit history in one query and:

- **restores the verdict**: when the last of `spam_moved`, `marked_junk`, `marked_not_junk`, `moved` and `archived` is
  `spam_moved`, the new row gets `spam_status` `moved` with the score and reason from the audit meta. A later
  `marked_not_junk` by a user, or a later `moved` or `archived` by a user of mail the filter moved (dragged out of the
  junk folder), gives `released` (with the score and reason of the `spam_moved`). Anything else after it, including a
  move without a user, leaves the status empty. `spam_checked_at` is set, so the
  message is not classified again;
- **announces returned mail**: a message whose last junk action is `marked_not_junk` and that was never announced,
  because the server put it straight in the junk folder, fires `MessageReceived` once in the watched folder it lands
  in, with a `received` audit entry;
- **moves allowlisted mail out of the junk folder**, see below.

Only folders that synced before are handled, like `MessageReceived`, and never `Sent`, `Drafts` or `Trash`. The
messages are handled in batches of 500, so every query stays bounded. Messages without a `Message-ID` are skipped.

### Mail in the junk folder

New mail in the folder with the `Junk` role whose sender is on the [allowlist](#the-allowlist) can be moved back to the
inbox with `MarkJunk::notJunk()` without a user. The server's own filter put it there; the allowlist says it is wanted.
Because a `From` header is easy to forge, and phishing with a forged `From` is exactly what lands in the junk folder,
every condition below has to hold:

1. The mail has no audit history other than `received`: mail a user marked as spam or moved to the junk folder in the
   app stays there.
2. The mail does not carry the `$Junk` keyword: mail a user marked as spam in another client (Apple Mail, Thunderbird)
   stays there. A server whose filter sets `$Junk` itself therefore never gets mail released.
3. The sender is authenticated. For these few candidates the headers are fetched once more, and the first (topmost)
   `Authentication-Results` header, the one the receiving server added, must hold `dmarc=pass` (with a `header.from`
   equal to the `From` domain, when given) or `dkim=pass` with a `header.d` equal to the `From` domain or a parent
   domain of it. SPF alone does not count. Without the header, or when it cannot be read, the mail stays.

With `spam.trusted_authserv_ids` set, the header must also come from one of those servers (the first word of the
header, for example `mx.example.com`). Set it when your mail server does not always add its own header on top: a
sender can put a forged `Authentication-Results` header in a mail, and then it is the topmost one.

When fetching or moving throws, the error is reported, the mail stays and the sync goes on.

Limits to know:

- The package only sees its own audit trail and the `$Junk` keyword. Mail a user moved to the junk folder in a client
  that sets no keyword has no history here, so authenticated mail of an allowlisted sender moved that way comes back.
  Remove the sender from the allowlist to stop that.
- In the inbox the allowlist trusts the `From` address without these checks: a forged `From` of an allowlisted address
  or domain skips the classifier there. Keep global domain entries for domains you trust.
- Mail without a `Message-ID` is never restored or moved out of the junk folder.

## Stored on the message

| Column            | Notes                                                                                   |
|-------------------|-----------------------------------------------------------------------------------------|
| `spam_score`      | The score from `0` to `1` with three decimals, `null` without a verdict                                   |
| `spam_reason`     | The reason the classifier gave, cut to 255 characters (also in the `spam_moved` meta)   |
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

This happens after the move succeeded: when the move throws, nothing is learned. Calls without a user, such as the
filter's own moves, teach nothing.

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
`add()` keeps an existing entry and its source, and throws an `InvalidArgumentException` for an empty pattern or one
that is only `@`. A sender without `@` matches only an entry with exactly that text, never a domain. The `source` tells where an entry came from:

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
