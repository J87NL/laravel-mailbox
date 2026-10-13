<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Testing\FakeMessage;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Testing\RecordingFolder;
use J87NL\Mailbox\Tests\Support\User;
use J87NL\Mailbox\Tests\TestCase;
use Symfony\Component\Mime\Email;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * @param  array<string, mixed>  $attributes
 */
function mailbox(array $attributes = []): Mailbox
{
    return Mailbox::query()
        ->create([
            'active' => true,
            'email' => 'team@example.com',
            'imap_host' => 'imap.example.com',
            'imap_password' => 'imap-secret',
            'imap_username' => 'team@example.com',
            'name' => 'Team',
            'smtp_host' => 'smtp.example.com',
            'smtp_password' => 'smtp-secret',
            'smtp_username' => 'team@example.com',
            ...$attributes,
        ])->refresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function folder(Mailbox $mailbox, string $path = 'INBOX', FolderRole $role = FolderRole::Inbox, array $attributes = []): MailboxFolder
{
    return MailboxFolder::query()
        ->create([
            'mailbox_id' => $mailbox->id,
            'name' => $path,
            'path' => $path,
            'role' => $role,
            'selectable' => true,
            ...$attributes,
        ])->refresh();
}

function remoteFolder(Mailbox $mailbox, string $path): RecordingFolder
{
    $remote = test()->connector->remote($mailbox);

    if (is_null($remote->folders()->find($path))) {
        $remote->addFolder(new RecordingFolder($path));
    }

    return $remote->folder($path);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function storedMessage(MailboxFolder $folder, int $uid, array $attributes = []): MailboxMessage
{
    return MailboxMessage::query()
        ->create([
            'mailbox_folder_id' => $folder->id,
            'mailbox_id' => $folder->mailbox_id,
            'message_id' => sprintf('message-%d@example.com', $uid),
            'subject' => sprintf('Message %d', $uid),
            'uid' => $uid,
            ...$attributes,
        ]);
}

function email(string $subject = 'Hello'): Email
{
    return (new Email)
        ->from('Sender Name <sender@example.org>')
        ->to('team@example.com')
        ->subject($subject)
        ->text('Plain body')
        ->html('<p>Html body</p>');
}

/**
 * @param  array<int, string>  $flags
 */
function remoteMessage(int $uid, array $flags = [], ?Email $email = null): FakeMessage
{
    $email ??= email(sprintf('Message %d', $uid));

    if (! $email->getHeaders()->has('Message-ID')) {
        $email->getHeaders()->addIdHeader('Message-ID', sprintf('message-%d@example.org', $uid));
    }

    return new FakeMessage($uid, $flags, $email->toString());
}

/**
 * @param  array<string, mixed>  $attributes
 */
function user(array $attributes = []): User
{
    return User::query()
        ->create([
            'email' => sprintf('user-%s@example.com', uniqid()),
            'name' => 'Test User',
            ...$attributes,
        ]);
}
