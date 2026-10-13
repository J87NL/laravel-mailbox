<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Testing;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\RawMessage;
use Throwable;

class RecordingTransport extends EsmtpTransport
{
    /**
     * @var array<int, RawMessage>
     */
    public array $sent = [];

    public ?Throwable $failure = null;

    public bool $started = false;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->failure) {
            throw $this->failure;
        }

        $this->sent[] = $message;

        return null;
    }

    public function start(): void
    {
        if ($this->failure) {
            throw $this->failure;
        }

        $this->started = true;
    }

    public function stop(): void
    {
        $this->started = false;
    }
}
