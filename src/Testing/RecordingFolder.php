<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Support\Carbon;
use J87NL\Mailbox\Spam\ServerSpamHeaders;
use Throwable;

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

    /**
     * @var array<int, string>
     */
    public array $searches = [];

    /**
     * @var array<int, array<int, string>>
     */
    public array $fetchItems = [];

    public int $messageFetches = 0;

    public int $chunkFetches = 0;

    public int $secondsPerChunk = 0;

    public int $fetches = 0;

    public int $secondsPerFetch = 0;

    public int $secondsPerSearch = 0;

    public int $secondsPerStatus = 0;

    public ?Throwable $moveFailure = null;

    public function advanceClock(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        Carbon::setTestNow(now()->addSeconds($seconds));
    }

    public function messages(): MessageQueryInterface
    {
        $this->select(true);

        return new RecordingMessageQuery($this);
    }

    public function status(): array
    {
        $this->advanceClock($this->secondsPerStatus);

        return $this->status;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    public function record(array $operation): void
    {
        $this->operations[] = $operation;
    }

    /**
     * @param  array<int, string>  $flags
     */
    public function addScoredMessage(int $uid, string $message, ?string $spamFlag, ?string $spamScore = null, array $flags = []): FakeMessage
    {
        $headers = collect([
            'flag' => $spamFlag,
            'score' => $spamScore,
        ])
            ->reject(fn (?string $value): bool => is_null($value))
            ->map(fn (string $value, string $key): string => sprintf("%s: %s\r\n", $this->serverSpamHeader($key), $value))
            ->implode('');

        $fake = new FakeMessage($uid, $flags, $headers.$message);

        $this->addMessage($fake);

        return $fake;
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

    private function serverSpamHeader(string $key): string
    {
        return ServerSpamHeaders::headerName($key) ?? ServerSpamHeaders::DEFAULT_HEADERS[$key];
    }
}
