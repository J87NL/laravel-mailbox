<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\MessageInterface;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Models\MailboxMessage;

class FetchMessageBody
{
    public function __construct(
        private readonly MailboxConnector $connector,
    ) {}

    public function handle(MailboxMessage $message): MailboxMessage
    {
        if ($message->hasBody()) {
            return $message;
        }

        $remote = $this->remote($message);

        $message->update([
            'attachments' => $this->describe($remote->attachments()),
            'body_fetched_at' => now(),
            'body_html' => $remote->html(),
            'body_text' => $remote->text(),
        ]);

        return $message;
    }

    public function attachment(MailboxMessage $message, int $index): ?Attachment
    {
        return $this->remote($message)->attachments()[$index] ?? null;
    }

    public function inlineAttachment(MailboxMessage $message, string $contentId): ?Attachment
    {
        return collect($this->remote($message)->attachments())
            ->first(fn (Attachment $attachment): bool => trim((string) $attachment->contentId(), '<>') === $contentId);
    }

    /**
     * @param  array<int, Attachment>  $attachments
     * @return array<int, array{content_id: ?string, content_type: string, filename: ?string, inline: bool}>
     */
    private function describe(array $attachments): array
    {
        return array_map(fn (Attachment $attachment): array => [
            'content_id' => $attachment->contentId() ? trim($attachment->contentId(), '<>') : null,
            'content_type' => $attachment->contentType(),
            'filename' => $attachment->filename(),
            'inline' => $this->isInline($attachment),
        ], $attachments);
    }

    private function isInline(Attachment $attachment): bool
    {
        $disposition = rescue(fn (): string => $attachment->contentDisposition(), '', report: false);

        return str_starts_with(strtolower($disposition), 'inline');
    }

    private function remote(MailboxMessage $message): MessageInterface
    {
        return $this->connector->folder($message->folder)
            ->messages()
            ->leaveUnread()
            ->withHeaders()
            ->withBody()
            ->withFlags()
            ->findOrFail($message->uid);
    }
}
