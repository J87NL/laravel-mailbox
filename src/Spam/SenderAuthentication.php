<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Spam;

use Illuminate\Support\Str;

class SenderAuthentication
{
    public function passes(?string $fromEmail, ?string $authenticationResults): bool
    {
        $domain = $this->domain($fromEmail);

        if ($domain === '' || is_null($authenticationResults) || trim($authenticationResults) === '') {
            return false;
        }

        $parts = explode(';', $this->withoutComments($authenticationResults));

        if (! $this->trustedServer(array_shift($parts))) {
            return false;
        }

        foreach ($parts as $result) {
            if ($this->passesFor($domain, $result)) {
                return true;
            }
        }

        return false;
    }

    private function passesFor(string $domain, string $result): bool
    {
        if (preg_match('/^\s*(dkim|dmarc)\s*=\s*([a-z]+)/i', $result, $match) !== 1 || Str::lower($match[2]) !== 'pass') {
            return false;
        }

        $properties = $this->properties($result);

        if (Str::lower($match[1]) === 'dmarc') {
            return ! isset($properties['header.from']) || $properties['header.from'] === $domain;
        }

        $signer = $properties['header.d'] ?? '';

        return $signer !== '' && ($signer === $domain || str_ends_with($domain, sprintf('.%s', $signer)));
    }

    /**
     * @return array<string, string>
     */
    private function properties(string $result): array
    {
        preg_match_all('/([a-z]+\.[a-z0-9_-]+)\s*=\s*"?([^\s";]+)"?/i', $result, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [Str::lower($match[1]) => Str::lower($match[2])])
            ->all();
    }

    private function trustedServer(?string $authservId): bool
    {
        $trusted = array_map(fn (string $id): string => Str::lower(trim($id)), (array) config('mailbox.spam.trusted_authserv_ids', []));

        if ($trusted === []) {
            return true;
        }

        $id = Str::lower((string) Str::of((string) $authservId)->trim()->explode(' ')->first());

        return in_array($id, $trusted, true);
    }

    private function withoutComments(string $value): string
    {
        return (string) preg_replace('/\([^)]*\)/', ' ', (string) preg_replace('/\s+/', ' ', $value));
    }

    private function domain(?string $email): string
    {
        if (is_null($email) || ! str_contains($email, '@')) {
            return '';
        }

        return Str::lower(trim(Str::afterLast($email, '@')));
    }
}
