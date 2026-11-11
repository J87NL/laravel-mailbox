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
