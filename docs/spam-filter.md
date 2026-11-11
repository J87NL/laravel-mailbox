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

## Stored on the message

| Column            | Notes                                                                                   |
|-------------------|-----------------------------------------------------------------------------------------|
| `spam_score`      | The score from `0` to `1`, `null` without a verdict                                     |
| `spam_reason`     | The reason the classifier gave                                                          |
| `spam_status`     | A `SpamStatus` enum: `suggested`, `moved` or `released`, or `null`                      |
| `spam_checked_at` | When the filter looked at the message; also set when it skipped or found no verdict     |

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
