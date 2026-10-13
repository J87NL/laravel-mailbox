<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Html\MessageHtml;
use J87NL\Mailbox\Jobs\SyncMailboxJob;
use J87NL\Mailbox\Models\Mailbox;

class SaveMailbox
{
    private const SECRETS = [
        'imap_password',
        'smtp_password',
    ];

    private const CONNECTION_FIELDS = [
        'imap_password' => [
            'imap_encryption',
            'imap_host',
            'imap_port',
            'imap_username',
        ],
        'smtp_password' => [
            'smtp_host',
            'smtp_port',
            'smtp_tls',
            'smtp_username',
        ],
    ];

    public function __construct(
        private readonly MessageHtml $html,
        private readonly RecordAudit $audit,
        private readonly TestConnection $testConnection,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?Mailbox $mailbox, array $data, ?Authenticatable $user, bool $test = true): Mailbox
    {
        $creating = is_null($mailbox);
        $mailbox ??= new Mailbox;
        $keptSecrets = $creating ? [] : $this->keptSecrets($data);

        if (array_key_exists('signature', $data)) {
            $data['signature'] = $this->html->sanitizeSignature($data['signature']);
        }

        $mailbox->fill(Arr::except($data, $keptSecrets));

        $this->assertServerChangesAllowed($mailbox, $keptSecrets, $user, $creating);

        if ($test) {
            $this->assertConnects($mailbox);
        }

        $changed = array_values(array_diff(array_keys($mailbox->getDirty()), self::SECRETS));

        $mailbox->save();

        $this->audit->forMailbox(
            $mailbox,
            $creating ? AuditAction::MailboxCreated : AuditAction::MailboxUpdated,
            $user,
            meta: [
                'changed' => $changed,
            ],
        );

        SyncMailboxJob::dispatch($mailbox->id);

        return $mailbox;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function keptSecrets(array $data): array
    {
        return array_values(array_filter(
            self::SECRETS,
            fn (string $secret): bool => ($data[$secret] ?? '') === '',
        ));
    }

    /**
     * @param  array<int, string>  $keptSecrets
     */
    private function assertServerChangesAllowed(Mailbox $mailbox, array $keptSecrets, ?Authenticatable $user, bool $creating): void
    {
        if ($creating) {
            return;
        }

        if ($mailbox->usesOAuth()) {
            $this->assertMayRedirectToken($mailbox, $user);

            return;
        }

        $missing = array_filter(
            $keptSecrets,
            fn (string $secret): bool => $mailbox->isDirty(self::CONNECTION_FIELDS[$secret]),
        );

        if ($missing === []) {
            return;
        }

        throw ValidationException::withMessages(array_fill_keys($missing, __('mailbox::mailbox.password_required_on_change')));
    }

    private function assertMayRedirectToken(Mailbox $mailbox, ?Authenticatable $user): void
    {
        $changed = array_filter(
            array_merge(...array_values(self::CONNECTION_FIELDS)),
            fn (string $field): bool => $mailbox->isDirty($field),
        );

        if ($changed === [] || is_null($user) || Gate::forUser($user)->allows('manageMailboxes')) {
            return;
        }

        throw ValidationException::withMessages(array_fill_keys(array_values($changed), __('mailbox::mailbox.oauth_server_admin_only')));
    }

    private function assertConnects(Mailbox $mailbox): void
    {
        $result = $this->testConnection->handle($mailbox);

        if ($this->testConnection->passes($result)) {
            return;
        }

        throw ValidationException::withMessages(array_filter([
            'imap_host' => $result['imap'] ? __('mailbox::mailbox.imap_failed', ['error' => $result['imap']]) : null,
            'smtp_host' => $result['smtp'] ? __('mailbox::mailbox.smtp_failed', ['error' => $result['smtp']]) : null,
        ]));
    }
}
