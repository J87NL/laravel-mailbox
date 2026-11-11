<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use DirectoryTree\ImapEngine\MessageInterface;

final class ServerSpamHeaders
{
    private const MAX_SCORE = 9999.99;

    /**
     * @return array{server_spam_flag: bool|null, server_spam_score: float|null}
     */
    public function attributes(MessageInterface $message): array
    {
        return [
            'server_spam_flag' => $this->flag($this->value($message, 'flag')),
            'server_spam_score' => $this->score($this->value($message, 'score')),
        ];
    }

    private function value(MessageInterface $message, string $key): ?string
    {
        $name = config(sprintf('mailbox.spam.server_headers.%s', $key));

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $value = $message->header(trim($name))?->getValue();

        if (! is_string($value)) {
            return null;
        }

        return trim($value);
    }

    private function flag(?string $value): ?bool
    {
        return match (strtoupper((string) $value)) {
            'YES' => true,
            'NO' => false,
            default => null,
        };
    }

    private function score(?string $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $score = round((float) $value, 2);

        if (! is_finite($score) || abs($score) > self::MAX_SCORE) {
            return null;
        }

        return $score;
    }
}
