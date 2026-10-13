<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Jobs\SyncMailboxJob;
use J87NL\Mailbox\Models\Mailbox;

class SaveMailbox
{
    private const SECRETS = ['imap_password', 'smtp_password'];

    public function __construct(
        private readonly RecordAudit $audit,
        private readonly TestConnection $testConnection,
    ) {}

    public function handle(?Mailbox $mailbox, array $data, ?Authenticatable $user, bool $test = true): Mailbox
    {
        $creating = is_null($mailbox);
        $mailbox ??= new Mailbox;

        foreach (self::SECRETS as $secret) {
            if (($data[$secret] ?? '') === '' && ! $creating) {
                unset($data[$secret]);
            }
        }

        $mailbox->fill($data);

        if ($test) {
            $this->assertConnects($mailbox);
        }

        $mailbox->save();

        $this->audit->forMailbox(
            $mailbox,
            $creating ? AuditAction::MailboxCreated : AuditAction::MailboxUpdated,
            $user,
            meta: ['changed' => array_values(array_diff(array_keys($mailbox->getChanges()), [...self::SECRETS, 'updated_at']))],
        );

        SyncMailboxJob::dispatch($mailbox->id);

        return $mailbox;
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
