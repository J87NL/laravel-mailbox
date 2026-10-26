<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Connection;

use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;

class TimeoutImapStream extends ImapStream
{
    public function __construct(
        private readonly int $readTimeout,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function open(string $transport, string $host, int $port, int $timeout, array $options = []): bool
    {
        $opened = parent::open($transport, $host, $port, $timeout, $options);

        $this->setTimeout($this->readTimeout);

        return $opened;
    }

    public function read(int $length): string|false
    {
        if (! $this->opened()) {
            return false;
        }

        $data = '';

        while (strlen($data) < $length && ! feof($this->stream)) {
            $chunk = fread($this->stream, $length - strlen($data));

            if ($chunk === false || ($chunk === '' && ($this->meta()['timed_out'] ?? false))) {
                return false;
            }

            $data .= $chunk;
        }

        return $data;
    }
}
