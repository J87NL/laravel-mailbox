<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Support\Str;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

class FakeConnector extends MailboxConnector
{
    /**
     * @var array<string, RecordingMailbox>
     */
    public array $mailboxes = [];

    /**
     * @var array<int, array{folder: string, message: string, flags: array<int, string>}>
     */
    public array $appended = [];

    /**
     * @var array<int, array{folder: string, uids: array<int, int>}>
     */
    public array $expunged = [];

    public bool $supportsUidPlus = true;

    public ?Throwable $appendFailure = null;

    public ?Throwable $imapFailure = null;

    public RecordingTransport $transport;

    public function __construct()
    {
        $this->transport = new RecordingTransport;
    }

    public static function install(): self
    {
        $connector = new self;

        app()->instance(MailboxConnector::class, $connector);

        return $connector;
    }

    public function remote(Mailbox $mailbox): RecordingMailbox
    {
        $key = $mailbox->exists ? (string) $mailbox->getKey() : 'unsaved';

        return $this->mailboxes[$key] ??= new RecordingMailbox(folders: [
            new RecordingFolder('INBOX'),
        ]);
    }

    public function imap(Mailbox $mailbox): MailboxInterface
    {
        if ($this->imapFailure) {
            throw $this->imapFailure;
        }

        return $this->remote($mailbox);
    }

    public function append(MailboxFolder $folder, string $message, array $flags = []): void
    {
        if ($this->appendFailure) {
            throw $this->appendFailure;
        }

        $this->appended[] = [
            'flags' => $flags,
            'folder' => $folder->path,
            'message' => $message,
        ];
    }

    public function expunge(MailboxFolder $folder, array $uids): bool
    {
        if (! $this->supportsUidPlus) {
            return false;
        }

        $remote = $this->remote($folder->mailbox)->folder(Str::fromImapUtf7($folder->path));

        $remote->setMessages(array_values(array_filter(
            $remote->getMessages(),
            fn ($message): bool => ! in_array($message->uid(), $uids, true),
        )));

        $this->expunged[] = [
            'folder' => $folder->path,
            'uids' => $uids,
        ];

        return true;
    }

    public function smtp(Mailbox $mailbox): EsmtpTransport
    {
        return $this->transport;
    }

    public function failImap(string $message = 'Connection refused'): void
    {
        $this->imapFailure = new RuntimeException($message);
    }
}
