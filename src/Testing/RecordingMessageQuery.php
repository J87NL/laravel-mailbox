<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use BackedEnum;
use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\Testing\FakeMessageQuery;

class RecordingMessageQuery extends FakeMessageQuery
{
    /**
     * @var array<int, int>|null
     */
    private ?array $uids = null;

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
        }

        return $this;
    }

    public function get(): MessageCollection
    {
        $messages = parent::get();

        if (is_null($this->uids)) {
            return $messages;
        }

        return new MessageCollection($messages
            ->filter(fn (MessageInterface $message): bool => in_array($message->uid(), $this->uids, true))
            ->values()
            ->all());
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
}
