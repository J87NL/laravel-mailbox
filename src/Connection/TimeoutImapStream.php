<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Connection;

use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;

class TimeoutImapStream extends ImapStream
{
    private ?float $deadline = null;

    public function __construct(
        private readonly int $readTimeout,
    ) {}

    public function readUntil(?float $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function open(string $transport, string $host, int $port, int $timeout, array $options = []): bool
    {
        $opened = parent::open($transport, $host, $port, $timeout, $options);

        $this->setTimeout($this->readTimeout);

        return $opened;
    }

    public function fgets(): string|false
    {
        if (! $this->limitToDeadline()) {
            return false;
        }

        return parent::fgets();
    }

    public function read(int $length): string|false
    {
        if (! $this->opened()) {
            return false;
        }

        $data = '';

        while (strlen($data) < $length && ! feof($this->stream)) {
            if (! $this->limitToDeadline()) {
                return false;
            }

            $chunk = fread($this->stream, $length - strlen($data));

            if ($chunk === false || ($chunk === '' && ($this->meta()['timed_out'] ?? false))) {
                return false;
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = parent::meta();

        if ($meta !== [] && $this->deadlinePassed()) {
            $meta['timed_out'] = true;
        }

        return $meta;
    }

    private function limitToDeadline(): bool
    {
        if (is_null($this->deadline) || ! $this->opened()) {
            return true;
        }

        if ($this->deadlinePassed()) {
            return false;
        }

        $this->setTimeout(min($this->readTimeout, max(1, (int) ceil($this->deadline - microtime(true)))));

        return true;
    }

    private function deadlinePassed(): bool
    {
        return ! is_null($this->deadline) && microtime(true) >= $this->deadline;
    }
}
