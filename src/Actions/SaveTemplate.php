<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Actions;

use J87NL\Mailbox\Html\MessageHtml;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxTemplate;

class SaveTemplate
{
    public function __construct(
        private readonly MessageHtml $html,
    ) {}

    /**
     * @param  array{body: string, name: string, subject?: string|null}  $data
     */
    public function handle(Mailbox $mailbox, ?MailboxTemplate $template, array $data): MailboxTemplate
    {
        $template ??= new MailboxTemplate(['mailbox_id' => $mailbox->id]);

        $template->fill([
            'body' => (string) $this->html->sanitizeSignature($data['body']),
            'name' => $data['name'],
            'subject' => ($data['subject'] ?? '') === '' ? null : $data['subject'],
        ]);

        $template->save();

        return $template;
    }
}
