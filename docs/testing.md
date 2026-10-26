# Testing

The package ships fakes for IMAP and SMTP in `J87NL\Mailbox\Testing`. With them, your tests never reach a mail server.

## Installing the fake

```php
use J87NL\Mailbox\Testing\FakeConnector;

$connector = FakeConnector::install();
```

`install()` binds a `FakeConnector` as the `MailboxConnector` in the container and returns it. Every action, sync class
and job then uses the fake. Call it before resolving any action, for example in `setUp()` or a `beforeEach()`.

## Remote folders and messages

Each mailbox gets a fake remote mailbox with an `INBOX` folder. Fill it with imapengine's `FakeMessage`:

```php
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use J87NL\Mailbox\Testing\RecordingFolder;

$remote = $connector->remote($mailbox);

$remote->folder('INBOX')->setMessages([
    new FakeMessage(1, [ImapFlag::Seen->value], $email->toString()),
]);
$remote->folder('INBOX')->status = ['UIDVALIDITY' => 1, 'UNSEEN' => 0];

$remote->addFolder(new RecordingFolder('Archive', ['\\Archive']));
```

The third argument of `FakeMessage` is the raw message; a Symfony `Email` with `toString()` is an easy way to build one.

Then run the code under test, for example the sync:

```php
use J87NL\Mailbox\Sync\SyncMailbox;

app(SyncMailbox::class)->handle($mailbox);
```

## Asserting IMAP operations

`RecordingFolder::$operations` records every flag change, move and destroy on that folder:

```php
app(MoveMessages::class)->handle($messages, $archive, $user);

expect($connector->remote($mailbox)->folder('INBOX')->operations)->toBe([
    ['target' => 'Archive', 'type' => 'move', 'uids' => [1]],
]);
```

| Type      | Keys                                    |
|-----------|-----------------------------------------|
| `flag`    | `flag`, `operation` (`+` or `-`), `uids` |
| `move`    | `target`, `uids`                        |
| `destroy` | `expunge`, `uids`                       |

`RecordingFolder::message(int $uid)` returns one fake message, for example to check its flags.
`RecordingFolder::$messageFetches` counts single-message fetches (`find()` and `findOrFail()`), for example to check
that inline images are served from the raw cache. Fake the raw cache disk with `Storage::fake('local')`.
`RecordingFolder::$chunkFetches` counts the chunks the sync fetched, and `RecordingFolder::$secondsPerChunk` moves the
test clock (`Carbon::setTestNow()`) that many seconds per chunk, so a test can use up a sync's time budget. The fake
message query honours UID ranges like a server does.

The connector records more:

| Property                | Holds                                                       |
|-------------------------|-------------------------------------------------------------|
| `$connector->appended`  | Messages appended to a folder: `folder`, `message`, `flags` |
| `$connector->expunged`  | `UID EXPUNGE` calls: `folder`, `uids`                       |
| `$connector->transport->sent` | Sent messages (Symfony `Email` objects)               |

## Sent mail

```php
use Symfony\Component\Mime\Email;

app(SendMessage::class)->handle($mailbox, $data, $user);

/** @var Email $sent */
$sent = $connector->transport->sent[0];

expect($sent->getSubject())->toBe('Your order');
```

## Simulating failures

```php
$connector->failImap('Connection refused');             // every IMAP connection throws
$connector->appendFailure = new RuntimeException('NO'); // storing in Sent fails
$connector->transport->failure = new RuntimeException('550 rejected'); // SMTP fails
$connector->supportsUidPlus = false;                    // purge flags \Deleted but does not expunge
```

`imapFailure` can also be set to any `Throwable` directly.

## Queues and events

The actions queue sync jobs and dispatch events. Fake them as usual:

```php
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use J87NL\Mailbox\Events\MessageReceived;
use J87NL\Mailbox\Jobs\SyncFolderJob;

Bus::fake([SyncFolderJob::class]);
Event::fake([MessageReceived::class]);
```

## Developing the package

```bash
composer install
composer test
composer analyse
composer format
```

The tests use Pest on Orchestra Testbench with an in-memory SQLite database. `UID EXPUNGE` is also checked at protocol
level with imapengine's `FakeStream`.
