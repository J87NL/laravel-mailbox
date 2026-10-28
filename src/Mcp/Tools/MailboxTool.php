<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Mcp\Tools;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxMessage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class MailboxTool extends Tool
{
    public const string ANY_ABILITY = 'accessAnyMailbox';

    public const string READ_ABILITY = 'accessMailbox';

    public const string WRITE_ABILITY = 'workInMailbox';

    protected Authenticatable $user;

    public function handle(Request $request): Response
    {
        $user = $this->mailboxUser($request);

        if (is_null($user)) {
            return Response::error('The mailbox tools need a signed in user');
        }

        if (! $this->isAvailableTo($user)) {
            return Response::error('You do not have access to any mailbox');
        }

        $this->user = $user;

        return $this->run($request);
    }

    public function isAvailableTo(?Authenticatable $user): bool
    {
        if (is_null($user)) {
            return false;
        }

        return Gate::forUser($user)->allows(self::ANY_ABILITY);
    }

    abstract protected function run(Request $request): Response;

    protected function mailboxUser(Request $request): ?Authenticatable
    {
        return $request->user();
    }

    protected function gate(): GateContract
    {
        return Gate::forUser($this->user);
    }

    protected function findMailbox(mixed $id): ?Mailbox
    {
        $mailboxId = filter_var($id, FILTER_VALIDATE_INT);

        if ($mailboxId === false) {
            return null;
        }

        $mailbox = Mailbox::query()
            ->find($mailboxId);

        if (is_null($mailbox) || $this->gate()->denies(self::READ_ABILITY, $mailbox)) {
            return null;
        }

        return $mailbox;
    }

    protected function findMessage(Mailbox $mailbox, mixed $id): ?MailboxMessage
    {
        $messageId = filter_var($id, FILTER_VALIDATE_INT);

        if ($messageId === false) {
            return null;
        }

        return MailboxMessage::query()
            ->with('folder')
            ->where('mailbox_id', $mailbox->id)
            ->find($messageId);
    }

    protected function mailboxNotFound(mixed $id): Response
    {
        return Response::error(sprintf("Mailbox '%s' not found", is_scalar($id) ? $id : ''));
    }

    protected function messageNotFound(mixed $id): Response
    {
        return Response::error(sprintf("Message '%s' not found", is_scalar($id) ? $id : ''));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): Response
    {
        return Response::text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<int, string>
     */
    protected function headerColumns(): array
    {
        return [
            'id',
            'mailbox_id',
            'mailbox_folder_id',
            'subject',
            'from_email',
            'from_name',
            'sent_at',
            'is_seen',
            'is_flagged',
            'is_answered',
            'has_attachments',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function messageHeaders(MailboxMessage $message): array
    {
        return [
            'id' => $message->id,
            'folder_id' => $message->mailbox_folder_id,
            'subject' => $message->subject,
            'from_email' => $message->from_email,
            'from_name' => $message->from_name,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'is_seen' => $message->is_seen,
            'is_flagged' => $message->is_flagged,
            'is_answered' => $message->is_answered,
            'has_attachments' => $message->has_attachments,
        ];
    }
}
