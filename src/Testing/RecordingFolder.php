<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMessage;

class RecordingFolder extends FakeFolder
{
    /**
     * @var array<string, int|string>
     */
    public array $status = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $operations = [];

    public int $messageFetches = 0;

    public int $chunkFetches = 0;

    public int $secondsPerChunk = 0;

    public function messages(): MessageQueryInterface
    {
        $this->select(true);

        return new RecordingMessageQuery($this);
    }

    public function status(): array
    {
        return $this->status;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    public function record(array $operation): void
    {
        $this->operations[] = $operation;
    }

    public function message(int $uid): ?FakeMessage
    {
        foreach ($this->messages as $message) {
            if ($message->uid() === $uid) {
                return $message;
            }
        }

        return null;
    }
}
