<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use J87NL\Mailbox\Models\MailboxContact;
use J87NL\Mailbox\Models\MailboxTemplate;
use J87NL\Mailbox\Support\DisplayTime;

class RenderTemplate
{
    public const PLACEHOLDERS = [
        'datum' => 'date',
        'date' => 'date',
        'first_name' => 'first_name',
        'mailbox_naam' => 'mailbox_name',
        'mailbox_name' => 'mailbox_name',
        'mijn_naam' => 'my_name',
        'my_name' => 'my_name',
        'naam' => 'name',
        'name' => 'name',
        'onderwerp' => 'subject',
        'subject' => 'subject',
        'voornaam' => 'first_name',
    ];

    /**
     * @return array{body: string, subject: string|null}
     */
    public function handle(
        MailboxTemplate $template,
        ?Authenticatable $user,
        ?string $recipientEmail = null,
        ?string $recipientName = null,
        ?string $subject = null,
    ): array {
        $values = $this->values($template, $user, $recipientEmail, $recipientName, $subject);

        return [
            'body' => $this->fill($template->body, $values, true),
            'subject' => is_null($template->subject) ? null : $this->fill($template->subject, $values, false),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function values(MailboxTemplate $template, ?Authenticatable $user, ?string $recipientEmail, ?string $recipientName, ?string $subject): array
    {
        $name = $this->recipientName($template, $recipientEmail, $recipientName);

        return [
            'date' => DisplayTime::format(now(), 'd-m-Y'),
            'first_name' => $this->firstName($name),
            'mailbox_name' => $template->mailbox->name,
            'my_name' => $this->userName($user),
            'name' => $name,
            'subject' => $this->present($subject),
        ];
    }

    private function recipientName(MailboxTemplate $template, ?string $recipientEmail, ?string $recipientName): ?string
    {
        $name = $this->present($recipientName);

        if (! is_null($name) && ! str_contains($name, '@')) {
            return $name;
        }

        if (is_null($this->present($recipientEmail))) {
            return null;
        }

        $contact = MailboxContact::query()
            ->where('mailbox_id', $template->mailbox_id)
            ->where('email', Str::lower(trim((string) $recipientEmail)))
            ->first();

        $contactName = $this->present($contact?->name);

        return is_null($contactName) || str_contains($contactName, '@') ? null : $contactName;
    }

    private function firstName(?string $name): ?string
    {
        if (is_null($name)) {
            return null;
        }

        $given = str_contains($name, ',') ? trim(Str::after($name, ',')) : $name;

        return $this->present(Str::before($given, ' '));
    }

    private function userName(?Authenticatable $user): ?string
    {
        if (is_null($user)) {
            return null;
        }

        $label = config('mailbox.access.user_label', 'name');

        return $this->present(is_string($label) ? (string) data_get($user, $label) : null);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function fill(string $text, array $values, bool $html): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', function (array $match) use ($html, $values): string {
            $key = self::PLACEHOLDERS[$match[1]] ?? null;
            $value = is_null($key) ? null : $values[$key];

            if (is_null($value)) {
                return $match[0];
            }

            return $html ? e($value) : $value;
        }, $text);
    }

    private function present(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
