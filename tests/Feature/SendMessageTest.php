<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use J87NL\Mailbox\Actions\SendMessage;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Events\MessageSent;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

beforeEach(function (): void {
    Bus::fake([SyncFolderJob::class]);
});

/**
 * @return array<string, string>
 */
function composed(array $overrides = []): array
{
    return [
        'bcc' => '',
        'body' => '<p>Thanks for your message</p><p>Second line</p>',
        'cc' => 'copy@example.org; second@example.org',
        'subject' => 'Re: Question',
        'to' => 'customer@example.org',
        ...$overrides,
    ];
}

it('sends with threading headers, stores a copy in Sent and marks the original answered', function (): void {
    Event::fake([MessageSent::class]);
    $mailbox = mailbox(['from_name' => 'Team']);
    $inbox = folder($mailbox);
    folder($mailbox, 'Sent', FolderRole::Sent);
    remoteFolder($mailbox, 'INBOX')->setMessages([remoteMessage(3)]);
    $original = storedMessage($inbox, 3, [
        'message_id' => 'question@example.org',
        'references' => '<first@example.org>',
    ])->load('folder');
    $actor = user();

    $messageId = app(SendMessage::class)->handle($mailbox, composed(), $actor, $original);

    /** @var Email $sent */
    $sent = test()->connector->transport->sent[0];
    $headers = $sent->getHeaders();

    expect($headers->get('In-Reply-To')->getBodyAsString())->toBe('<question@example.org>')
        ->and($headers->get('References')->getBodyAsString())->toBe('<first@example.org> <question@example.org>')
        ->and($headers->get('From')->getBodyAsString())->toBe('Team <team@example.com>')
        ->and(array_map(fn (Address $address): string => $address->getAddress(), $sent->getCc()))->toBe([
            'copy@example.org',
            'second@example.org',
        ])
        ->and($messageId)->toEndWith('@example.com')
        ->and($messageId)->not->toStartWith('<')
        ->and($sent->getTextBody())->toBe("Thanks for your message\nSecond line")
        ->and(test()->connector->appended)->toHaveCount(1)
        ->and(test()->connector->appended[0]['folder'])->toBe('Sent')
        ->and(test()->connector->appended[0]['flags'])->toBe([ImapFlag::Seen->value])
        ->and(remoteFolder($mailbox, 'INBOX')->message(3)->flags())->toContain(ImapFlag::Answered->value)
        ->and(MailboxMessage::query()
            ->find($original->id)->is_answered)->toBeTrue();

    $audit = MailboxAuditEntry::query()
        ->sole();

    expect($audit->action)->toBe(AuditAction::Sent)
        ->and($audit->user_id)->toBe($actor->id)
        ->and($audit->message_id)->toBe($messageId);

    Event::assertDispatched(MessageSent::class);
    Bus::assertDispatched(SyncFolderJob::class, fn (SyncFolderJob $job): bool => $job->folderId === $mailbox->folderWithRole(FolderRole::Sent)?->id);
});

it('skips the Sent copy when the server stores sent mail itself', function (): void {
    $mailbox = mailbox(['append_sent' => false]);

    app(SendMessage::class)->handle($mailbox, composed(), null);

    expect(test()->connector->transport->sent)->toHaveCount(1)
        ->and(test()->connector->appended)->toBe([]);
    Bus::assertNotDispatched(SyncFolderJob::class);
});

it('counts the message as sent and reports when storing the Sent copy fails', function (): void {
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reported): bool {
        $reported[] = $exception->getMessage();

        return false;
    });

    $mailbox = mailbox();
    folder($mailbox, 'Sent', FolderRole::Sent);
    test()->connector->appendFailure = new RuntimeException('APPEND failed');

    $messageId = app(SendMessage::class)->handle($mailbox, composed(), null);

    expect($messageId)->not->toBe('')
        ->and(test()->connector->transport->sent)->toHaveCount(1)
        ->and($reported)->toBe(['APPEND failed'])
        ->and(MailboxAuditEntry::query()
            ->sole()->action)->toBe(AuditAction::Sent);
});

it('records nothing when SMTP refuses the message', function (): void {
    $mailbox = mailbox();
    test()->connector->transport->failure = new RuntimeException('Relay denied');

    expect(fn (): string => app(SendMessage::class)->handle($mailbox, composed(), null))->toThrow(RuntimeException::class, 'Relay denied');
    expect(MailboxAuditEntry::query()
        ->count())->toBe(0)
        ->and(test()->connector->appended)->toBe([]);
});

it('sends to address lists and attaches forwarded originals', function (): void {
    $mailbox = mailbox(['append_sent' => false]);
    $original = new Attachment(
        'offer.pdf',
        null,
        'application/pdf',
        'attachment',
        Utils::streamFor('PDF-CONTENT'),
    );

    app(SendMessage::class)->handle($mailbox, composed([
        'cc' => [
            'copy@example.org',
            'second@example.org',
        ],
        'forwarded_attachments' => [$original],
        'to' => [
            'customer@example.org',
            'other@example.org',
        ],
    ]), null);

    /** @var Email $sent */
    $sent = test()->connector->transport->sent[0];

    expect(array_map(fn (Address $address): string => $address->getAddress(), $sent->getTo()))->toBe([
        'customer@example.org',
        'other@example.org',
    ])
        ->and(array_map(fn (Address $address): string => $address->getAddress(), $sent->getCc()))->toBe([
            'copy@example.org',
            'second@example.org',
        ])
        ->and(array_map(fn (DataPart $part): string => (string) $part->getFilename(), $sent->getAttachments()))->toBe(['offer.pdf'])
        ->and($sent->getAttachments()[0]->getBody())->toBe('PDF-CONTENT');
});
