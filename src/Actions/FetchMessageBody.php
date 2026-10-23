<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\FileMessage;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ItemNotFoundException;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Exceptions\MessageGoneException;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Support\RawMessageCache;

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

    /**
     * @param  array<int, int>  $indexes
     * @return array<int, Attachment|null>
     */
    public function attachmentsAt(MailboxMessage $message, array $indexes): array
    {
        $attachments = $this->remote($message)->attachments();

        return array_map(fn (int $index): ?Attachment => $attachments[$index] ?? null, $indexes);
    }

    /**
     * @return array<string, Attachment>
     */
    public function inlineAttachments(MailboxMessage $message): array
    {
        return collect($this->remote($message)->attachments())
            ->filter(fn (Attachment $attachment): bool => trim((string) $attachment->contentId(), '<> ') !== '')
            ->keyBy(fn (Attachment $attachment): string => trim((string) $attachment->contentId(), '<>'))
            ->all();
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
        $cached = $this->cached($message);

        if (! is_null($cached) || ! $this->raw()->enabled()) {
            return $cached ?? $this->fetch($message);
        }

        try {
            return Cache::lock(sprintf('mailbox:raw:%d', $message->getKey()), 60)
                ->block(30, fn (): MessageInterface => $this->cached($message) ?? $this->fetchAndRemember($message));
        } catch (LockTimeoutException) {
            return $this->fetch($message);
        }
    }

    private function cached(MailboxMessage $message): ?MessageInterface
    {
        $raw = $this->raw()->get($message);

        return is_null($raw) ? null : new FileMessage($raw);
    }

    private function fetchAndRemember(MailboxMessage $message): MessageInterface
    {
        $remote = $this->fetch($message);

        $this->raw()->put($message, (string) $remote);

        return $remote;
    }

    private function fetch(MailboxMessage $message): MessageInterface
    {
        try {
            return $this->connector->folder($message->folder)
                ->messages()
                ->leaveUnread()
                ->withHeaders()
                ->withBody()
                ->withFlags()
                ->findOrFail($message->uid);
        } catch (ItemNotFoundException $exception) {
            throw MessageGoneException::for($message, $exception);
        }
    }

    private function raw(): RawMessageCache
    {
        return app(RawMessageCache::class);
    }
}
