<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Facades\Log;

final class ServerSpamHeaders
{
    public const DEFAULT_HEADERS = [
        'flag' => 'X-Spam-Flag',
        'score' => 'X-Spam-Score',
    ];

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

    public static function headerName(string $key): ?string
    {
        $configKey = sprintf('mailbox.spam.server_headers.%s', $key);

        if (! config()->has($configKey)) {
            return self::DEFAULT_HEADERS[$key] ?? null;
        }

        $name = config($configKey);

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }

    private function value(MessageInterface $message, string $key): ?string
    {
        $name = self::headerName($key);

        if (is_null($name)) {
            return null;
        }

        $values = $this->occurrences($message, $name);

        if ($values === []) {
            return null;
        }

        if (count(array_unique($values)) > 1) {
            Log::info('A message carries conflicting spam headers, the mailbox keeps the last one.', [
                'count' => count($values),
                'header' => $name,
                'message_id' => $message->messageId(),
            ]);
        }

        return $values[array_key_last($values)];
    }

    /**
     * @return array<int, string>
     */
    private function occurrences(MessageInterface $message, string $name): array
    {
        $values = [];

        for ($offset = 0; ! is_null($header = $message->header($name, $offset)); $offset++) {
            $values[] = trim((string) $header->getValue());
        }

        return $values;
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
