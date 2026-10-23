<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Events\MessageSent;
use J87NL\Mailbox\Html\QuotedImages;
use J87NL\Mailbox\Jobs\SyncFolderJob;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class SendMessage
{
    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly EnsureFolder $ensureFolder,
        private readonly QuotedImages $quotedImages,
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
                'to' => array_map(fn (Address $address): string => $address->getAddress(), $email->getTo()),
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

        SyncFolderJob::dispatch($sent->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function compose(Mailbox $mailbox, array $data, ?MailboxMessage $replyTo): Email
    {
        $email = (new Email)
            ->from(new Address($mailbox->email, (string) $mailbox->from_name))
            ->to(...$this->addresses($data['to']))
            ->subject((string) $data['subject']);

        $html = $this->quotedImages->embed($email, (string) $data['body'], $data['quote_source'] ?? null);

        $email->html($html)
            ->text($this->plainText($html));

        $this->copies($email, $data);
        $this->attach($email, $data['attachments'] ?? []);
        $this->forward($email, $data['forwarded_attachments'] ?? []);

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
        $withoutStyles = preg_replace('/<(style|script|head)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $withLinks = preg_replace_callback('/<a\b[^>]*\bhref\s*=\s*(["\'])(https?:[^"\']+|mailto:[^"\']+)\1[^>]*>(.*?)<\/a>/is', $this->plainLink(...), $withoutStyles) ?? $withoutStyles;
        $withCells = preg_replace('/<\/(td|th)>/i', ' ', $withLinks) ?? $withLinks;
        $withBreaks = preg_replace('/<br\s*\/?>|<\/(p|li|div|blockquote|h[1-6]|tr)>/i', "\n", $withCells) ?? $withCells;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5);

        $lines = array_map(
            fn (string $line): string => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line),
            explode("\n", $text),
        );
        $joined = implode("\n", $lines);

        return trim(preg_replace("/\n{3,}/", "\n\n", $joined) ?? $joined);
    }

    /**
     * @param  array<int, string>  $match
     */
    private function plainLink(array $match): string
    {
        $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
        $label = trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5));
        $address = Str::after($url, 'mailto:');

        if ($label === '') {
            return e($address);
        }

        if ($label === $url || $label === $address) {
            return $match[3];
        }

        return sprintf('%s (%s)', $match[3], e($address));
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
     * @param  array<int, mixed>  $attachments
     */
    private function forward(Email $email, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if (! $attachment instanceof Attachment) {
                continue;
            }

            $email->attach($attachment->contents(), $attachment->filename() ?: 'attachment', $attachment->contentType());
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
