<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use BackedEnum;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\Testing\FakeMessageQuery;

class RecordingMessageQuery extends FakeMessageQuery
{
    /**
     * @var array<int, int>|null
     */
    private ?array $uids = null;

    /**
     * @var array{0: int, 1: int|float}|null
     */
    private ?array $uidRange = null;

    private bool $chunking = false;

    public function __construct(
        private readonly RecordingFolder $recordingFolder,
    ) {
        parent::__construct($recordingFolder);
    }

    public function uid(int|string|array $from, int|float|null $to = null): static
    {
        $this->query->uid($from, $to);

        if (is_array($from)) {
            $this->uids = array_map('intval', $from);

            return $this;
        }

        $this->uidRange = [
            (int) $from,
            is_null($to) ? (int) $from : $to,
        ];

        return $this;
    }

    public function get(): MessageCollection
    {
        if (! $this->chunking) {
            $this->recordingFolder->fetches++;
            $this->recordingFolder->advanceClock($this->recordingFolder->secondsPerFetch);
        }

        $messages = $this->withinUidRange(parent::get());

        if (! is_null($this->uids)) {
            $messages = new MessageCollection($messages
                ->filter(fn (MessageInterface $message): bool => in_array($message->uid(), $this->uids, true))
                ->values()
                ->all());
        }

        return $this->withinLimit($messages);
    }

    public function chunk(callable $callback, int $chunkSize = 10, int $startChunk = 1): void
    {
        $this->recordingFolder->advanceClock($this->recordingFolder->secondsPerSearch);
        $this->chunking = true;

        try {
            parent::chunk(function (MessageCollection $messages, int $page) use ($callback): mixed {
                $this->recordingFolder->chunkFetches++;
                $this->recordingFolder->advanceClock($this->recordingFolder->secondsPerChunk);

                return $callback($messages, $page);
            }, $chunkSize, $startChunk);
        } finally {
            $this->chunking = false;
        }
    }

    public function find(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): ?MessageInterface
    {
        $this->recordingFolder->messageFetches++;

        return parent::find($id, $identifier);
    }

    public function findOrFail(int $id, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): MessageInterface
    {
        $this->recordingFolder->messageFetches++;

        return parent::findOrFail($id, $identifier);
    }

    public function flag(BackedEnum|string $flag, string $operation, bool $expunge = false): int
    {
        $messages = $this->get();

        foreach ($messages as $message) {
            $message->flag($flag, $operation);
        }

        $this->recordingFolder->record([
            'flag' => $flag instanceof BackedEnum ? $flag->value : $flag,
            'operation' => $operation,
            'type' => 'flag',
            'uids' => $this->uids,
        ]);

        return $messages->count();
    }

    public function move(string $folder, bool $expunge = false): int
    {
        $this->recordingFolder->record([
            'target' => $folder,
            'type' => 'move',
            'uids' => $this->uids,
        ]);

        $remaining = array_filter(
            $this->folder->getMessages(),
            fn (MessageInterface $message): bool => ! in_array($message->uid(), $this->uids ?? [], true),
        );

        $this->folder->setMessages(array_values($remaining));

        return count($this->uids ?? []);
    }

    public function destroy(array|int $uids, bool $expunge = false): void
    {
        $uids = (array) $uids;

        $this->recordingFolder->record([
            'expunge' => $expunge,
            'type' => 'destroy',
            'uids' => $uids,
        ]);

        foreach ($this->folder->getMessages() as $message) {
            if (in_array($message->uid(), $uids, true)) {
                $message->flag(ImapFlag::Deleted, '+');
            }
        }
    }

    private function withinLimit(MessageCollection $messages): MessageCollection
    {
        if ($this->chunking || is_null($this->getLimit())) {
            return $messages;
        }

        $sorted = $messages->sortBy(fn (MessageInterface $message): int => $message->uid(), descending: $this->getFetchOrder() === 'desc');

        return new MessageCollection($sorted
            ->forPage($this->page, $this->getLimit())
            ->values()
            ->all());
    }

    private function withinUidRange(MessageCollection $messages): MessageCollection
    {
        if (is_null($this->uidRange)) {
            return $messages;
        }

        [$from, $to] = $this->uidRange;

        return new MessageCollection($messages
            ->filter(fn (MessageInterface $message): bool => $message->uid() >= $from && $message->uid() <= $to)
            ->values()
            ->all());
    }
}
