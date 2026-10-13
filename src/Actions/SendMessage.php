<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use DirectoryTree\ImapEngine\Enums\ImapFlag;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Events\MessageSent;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class SendMessage
{
    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly EnsureFolder $ensureFolder,
        private readonly MarkMessages $markMessages,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(
        Mailbox $mailbox,
        array $data,
        ?Authenticatable $user,
        ?MailboxMessage $replyTo = null,
    ): string {
        $email = $this->compose($mailbox, $data, $replyTo);

        $this->connector->smtp($mailbox)->send($email);

        $messageId = trim($email->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '', '<>');

        rescue(function () use ($mailbox, $email): void {
            $this->storeCopy($mailbox, $email);
        });

        if ($replyTo) {
            rescue(function () use ($replyTo): void {
                $this->markMessages->answered($replyTo);
            });
        }

        $this->audit->forMailbox(
            $mailbox,
            AuditAction::Sent,
            $user,
            messageId: $messageId,
            subject: $email->getSubject(),
            meta: [
                'in_reply_to' => $replyTo?->message_id,
                'to' => $data['to'],
            ],
        );

        MessageSent::dispatch($mailbox, $messageId, (string) $email->getSubject());

        return $messageId;
    }

    private function storeCopy(Mailbox $mailbox, Email $email): void
    {
        if (! $mailbox->append_sent) {
            return;
        }

        $sent = $this->ensureFolder->handle($mailbox, FolderRole::Sent, 'Sent');

        $this->connector->append($sent, $email->toString(), [ImapFlag::Seen->value]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function compose(Mailbox $mailbox, array $data, ?MailboxMessage $replyTo): Email
    {
        $email = (new Email)
            ->from(new Address($mailbox->email, (string) $mailbox->from_name))
            ->to(...$this->addresses($data['to']))
            ->subject((string) $data['subject'])
            ->html((string) $data['body'])
            ->text($this->plainText((string) $data['body']));

        $this->copies($email, $data);
        $this->attach($email, $data['attachments'] ?? []);

        $email->getHeaders()->addIdHeader('Message-ID', sprintf('%s@%s', Str::uuid()->toString(), Str::after($mailbox->email, '@')));

        if (! $replyTo?->message_id) {
            return $email;
        }

        $parentId = trim($replyTo->message_id, '<>');

        $email->getHeaders()->addIdHeader('In-Reply-To', $parentId);
        $email->getHeaders()->addTextHeader('References', trim(sprintf('%s <%s>', (string) $replyTo->references, $parentId)));

        return $email;
    }

    private function plainText(string $html): string
    {
        $withBreaks = (string) preg_replace('/<br\s*\/?>|<\/(p|li|div|blockquote|h[1-6]|tr)>/i', "\n", $html);

        return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * @param  array<int, mixed>  $files
     */
    private function attach(Email $email, array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $email->attachFromPath((string) $file->getRealPath(), $file->getClientOriginalName(), $file->getClientMimeType());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function copies(Email $email, array $data): void
    {
        if (! empty($data['cc'])) {
            $email->cc(...$this->addresses($data['cc']));
        }

        if (! empty($data['bcc'])) {
            $email->bcc(...$this->addresses($data['bcc']));
        }
    }

    /**
     * @param  array<int, string>|string  $value
     * @return array<int, Address>
     */
    private function addresses(array|string $value): array
    {
        return collect(is_array($value) ? $value : preg_split('/[,;]+/', $value))
            ->map(fn (mixed $address): string => trim((string) $address))
            ->filter()
            ->map(fn (string $address): Address => Address::create($address))
            ->values()
            ->all();
    }
}
