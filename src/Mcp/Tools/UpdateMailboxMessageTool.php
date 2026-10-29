<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Mcp\Tools;

use DirectoryTree\ImapEngine\Exceptions\Exception as ImapException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException as ImapRuntimeException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use J87NL\Mailbox\Actions\ArchiveMessages;
use J87NL\Mailbox\Actions\MarkMessages;
use J87NL\Mailbox\Exceptions\MailboxException;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxMessage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Tidy up one message of a mailbox you may work in (write access): mark it read or unread, flag or unflag it, or archive it (move it to the archive folder). Pass the mailbox id, the message id from list-mailbox-messages-tool and the action. The change is made on the mail server and recorded in the mailbox audit trail under your name. It never sends, replies, forwards or deletes mail.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld]
class UpdateMailboxMessageTool extends MailboxTool
{
    public const array ACTIONS = [
        'read',
        'unread',
        'flag',
        'unflag',
        'archive',
    ];

    protected function run(Request $request): Response
    {
        $action = (string) $request->get('action');

        if (! in_array($action, self::ACTIONS, true)) {
            return Response::error(sprintf('action must be one of: %s', implode(', ', self::ACTIONS)));
        }

        $mailbox = $this->findMailbox($request->get('mailbox_id'));

        if (is_null($mailbox)) {
            return $this->mailboxNotFound($request->get('mailbox_id'));
        }

        if ($this->gate()->denies(self::WRITE_ABILITY, $mailbox)) {
            return Response::error('You may only read this mailbox; changing its messages needs write access');
        }

        $message = $this->findMessage($mailbox, $request->get('message_id'));

        if (is_null($message)) {
            return $this->messageNotFound($request->get('message_id'));
        }

        try {
            $changed = $this->apply($action, $mailbox, $message);
        } catch (ImapException|ImapRuntimeException|MailboxException $e) {
            report($e);

            return Response::error('The mail server refused this change right now. Try again later.');
        }

        if ($action === 'archive' && $changed === 0) {
            return Response::error('This message is already in the archive folder');
        }

        if ($action === 'archive') {
            return $this->json([
                'id' => $message->id,
                'archived' => true,
                'note' => 'The message moved to the archive folder and gets a new id there after the next sync.',
            ]);
        }

        return $this->json($this->messageHeaders($message->refresh()));
    }

    private function apply(string $action, Mailbox $mailbox, MailboxMessage $message): int
    {
        $messages = collect([$message]);
        $mark = app(MarkMessages::class);

        if ($action === 'archive') {
            return app(ArchiveMessages::class)->handle($mailbox, $messages, $this->user);
        }

        match ($action) {
            'read' => $mark->read($messages, $this->user),
            'unread' => $mark->unread($messages, $this->user),
            'flag' => $mark->flag($messages, $this->user),
            'unflag' => $mark->unflag($messages, $this->user),
            default => throw new InvalidArgumentException(sprintf('Unknown mailbox message action %s', $action)),
        };

        return $messages->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mailbox_id' => $schema->integer()
                ->description('The mailbox id, as returned by list-mailboxes-tool')
                ->required(),
            'message_id' => $schema->integer()
                ->description('The message id, as returned by list-mailbox-messages-tool')
                ->required(),
            'action' => $schema->string()
                ->enum(self::ACTIONS)
                ->description('read or unread to change the read state, flag or unflag to change the flag, archive to move the message to the archive folder')
                ->required(),
        ];
    }
}
