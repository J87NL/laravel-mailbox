<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Connection;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Support\Str;
use J87NL\Mailbox\Exceptions\MailboxException;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class MailboxConnector
{
    /**
     * @var array<string, MailboxInterface>
     */
    private array $connections = [];

    public function imap(Mailbox $mailbox): MailboxInterface
    {
        $key = $this->key($mailbox);

        if (isset($this->connections[$key])) {
            return $this->connections[$key];
        }

        return $this->connections[$key] = ImapMailbox::make([
            'authentication' => $mailbox->usesOAuth() ? 'oauth' : 'plain',
            'encryption' => $mailbox->imap_encryption,
            'host' => $mailbox->imap_host,
            'password' => $mailbox->usesOAuth() ? $this->accessToken($mailbox) : $mailbox->imap_password,
            'port' => $mailbox->imap_port,
            'timeout' => $this->timeout(),
            'username' => $mailbox->imap_username,
        ]);
    }

    public function folder(MailboxFolder $folder): FolderInterface
    {
        return $this->folderByPath($folder->mailbox, $folder->path);
    }

    public function folderByPath(Mailbox $mailbox, string $path): FolderInterface
    {
        $folder = $this->imap($mailbox)->folders()->find(Str::fromImapUtf7($path));

        if (is_null($folder)) {
            throw MailboxException::folderNotFound($mailbox, $path);
        }

        return $folder;
    }

    /**
     * @param  array<int, string>  $flags
     */
    public function append(MailboxFolder $folder, string $message, array $flags = []): void
    {
        $this->imap($folder->mailbox)->connection()->append($folder->path, $message, $flags);
    }

    /**
     * @param  array<int, int>  $uids
     */
    public function expunge(MailboxFolder $folder, array $uids): bool
    {
        $imap = $this->imap($folder->mailbox);

        if (! $imap instanceof ImapMailbox) {
            return false;
        }

        $this->folder($folder)->select();

        return $imap->expungeUids($uids);
    }

    public function smtp(Mailbox $mailbox): EsmtpTransport
    {
        $transport = new EsmtpTransport($mailbox->smtp_host, (int) $mailbox->smtp_port, $mailbox->smtp_tls);

        $transport->setUsername((string) $mailbox->smtp_username);
        $transport->setRequireTls($mailbox->smtp_tls !== false);
        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout($this->timeout());
        }

        if (! $mailbox->usesOAuth()) {
            $transport->setPassword((string) $mailbox->smtp_password);

            return $transport;
        }

        $transport->setPassword($this->accessToken($mailbox));
        $transport->setAuthenticators([new XOAuth2Authenticator]);

        return $transport;
    }

    public function disconnect(Mailbox $mailbox): void
    {
        $key = $this->key($mailbox);

        if (! isset($this->connections[$key])) {
            return;
        }

        $this->connections[$key]->disconnect();

        unset($this->connections[$key]);
    }

    private function timeout(): int
    {
        return max(1, (int) config('mailbox.connection.timeout', 10));
    }

    private function key(Mailbox $mailbox): string
    {
        return $mailbox->exists ? (string) $mailbox->getKey() : 'unsaved';
    }

    private function accessToken(Mailbox $mailbox): string
    {
        $provider = config('mailbox.oauth_token_provider');

        if (! $provider) {
            throw MailboxException::missingTokenProvider($mailbox);
        }

        return app($provider)->accessToken($mailbox);
    }
}
