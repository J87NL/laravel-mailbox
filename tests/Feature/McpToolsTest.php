<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use J87NL\Mailbox\Actions\GrantAccess;
use J87NL\Mailbox\Enums\AccessLevel;
use J87NL\Mailbox\Enums\AuditAction;
use J87NL\Mailbox\Enums\FolderRole;
use J87NL\Mailbox\Mcp\MailboxMcp;
use J87NL\Mailbox\Mcp\Tools\GetMailboxMessageTool;
use J87NL\Mailbox\Mcp\Tools\ListMailboxesTool;
use J87NL\Mailbox\Mcp\Tools\ListMailboxMessagesTool;
use J87NL\Mailbox\Mcp\Tools\UpdateMailboxMessageTool;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxAccess;
use J87NL\Mailbox\Models\MailboxAuditEntry;
use J87NL\Mailbox\Models\MailboxMessage;
use J87NL\Mailbox\Tests\Support\McpServer;
use J87NL\Mailbox\Tests\Support\User;
use J87NL\Mailbox\Tests\Support\WithoutClients;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    Bus::fake();

    $this->owner = user([
        'email' => 'owner@example.com',
        'name' => 'Owner',
    ]);
    $this->mailbox = mcpMailboxFor($this->owner);
    $this->inbox = $this->mailbox->folders()->sole();
});

function mcpMailboxFor(User $owner): Mailbox
{
    $mailbox = mailbox([
        'email' => $owner->email,
        'name' => $owner->name,
    ]);

    folder($mailbox, attributes: [
        'synced_at' => now(),
        'unseen_count' => 1,
    ]);

    mcpGrant($mailbox, $owner, AccessLevel::Manage, $owner);

    return $mailbox;
}

function mcpGrant(Mailbox $mailbox, User $user, AccessLevel $level, User $by): void
{
    app(GrantAccess::class)->handle($mailbox, $user->getMorphClass(), $user->getKey(), $level, $by);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mcpMessage(Mailbox $mailbox, int $uid = 1, array $attributes = []): MailboxMessage
{
    return storedMessage($mailbox->folders()->where('role', FolderRole::Inbox)->sole(), $uid, [
        'message_id' => sprintf('%s@example.com', Str::random(12)),
        'subject' => 'Offerte website',
        'from_email' => 'klant@example.com',
        'from_name' => 'Klant',
        'sent_at' => now(),
        'is_seen' => false,
        ...$attributes,
    ])->refresh();
}

it('denies every tool to a user without a signed in session', function (): void {
    McpServer::tool(ListMailboxesTool::class, [])
        ->assertHasErrors(['signed in user']);

    expect(app(ListMailboxesTool::class)->isAvailableTo(null))->toBeFalse();
});

it('denies every tool to a user filtered by access.user_query, even with an access row', function (): void {
    config()->set('mailbox.access.user_query', WithoutClients::class);

    $client = user(['email' => 'jack@client.example']);
    $message = mcpMessage($this->mailbox);

    MailboxAccess::query()
        ->create([
            'accessor_id' => $client->id,
            'accessor_type' => $client->getMorphClass(),
            'level' => AccessLevel::Manage,
            'mailbox_id' => $this->mailbox->id,
        ]);

    McpServer::actingAs($client)->tool(ListMailboxesTool::class, [])
        ->assertHasErrors(['access to any mailbox']);
    McpServer::actingAs($client)->tool(ListMailboxMessagesTool::class, ['mailbox_id' => $this->mailbox->id])
        ->assertHasErrors();
    McpServer::actingAs($client)->tool(GetMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
    ])
        ->assertHasErrors()
        ->assertDontSee('Offerte website');
    McpServer::actingAs($client)->tool(UpdateMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
        'action' => 'read',
    ])->assertHasErrors();

    expect($message->refresh()->is_seen)->toBeFalse()
        ->and(app(ListMailboxesTool::class)->isAvailableTo($client))->toBeFalse();
});

it('hides a mailbox without access behind the same error as a missing one', function (): void {
    $colleague = user(['name' => 'Colleague']);
    $ownBox = mcpMailboxFor($colleague);
    $message = mcpMessage($this->mailbox);

    McpServer::actingAs($colleague)->tool(ListMailboxesTool::class, [])
        ->assertOk()
        ->assertSee($ownBox->email)
        ->assertDontSee($this->mailbox->email);

    McpServer::actingAs($colleague)->tool(ListMailboxMessagesTool::class, ['mailbox_id' => $this->mailbox->id])
        ->assertHasErrors([sprintf("Mailbox '%d' not found", $this->mailbox->id)]);

    McpServer::actingAs($colleague)->tool(ListMailboxMessagesTool::class, ['mailbox_id' => 999999])
        ->assertHasErrors(["Mailbox '999999' not found"]);

    McpServer::actingAs($colleague)->tool(GetMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
    ])
        ->assertHasErrors([sprintf("Mailbox '%d' not found", $this->mailbox->id)])
        ->assertDontSee('Offerte website');

    McpServer::actingAs($colleague)->tool(UpdateMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
        'action' => 'read',
    ])->assertHasErrors([sprintf("Mailbox '%d' not found", $this->mailbox->id)]);

    expect($message->refresh()->is_seen)->toBeFalse()
        ->and(remoteFolder($this->mailbox, 'INBOX')->operations)->toBe([]);
});

it('lists the mailbox with level and unread counts, and its messages newest first', function (): void {
    mcpMessage($this->mailbox, 1, [
        'subject' => 'Oud bericht',
        'sent_at' => now()->subDay(),
        'is_seen' => true,
    ]);
    mcpMessage($this->mailbox, 2, [
        'subject' => 'Nieuw bericht',
        'from_name' => 'Pietje Puk',
    ]);

    McpServer::actingAs($this->owner)->tool(ListMailboxesTool::class, [])
        ->assertOk()
        ->assertSee($this->mailbox->email)
        ->assertSee('"access_level": "manage"')
        ->assertSee('"unread": 1');

    $listing = McpServer::actingAs($this->owner)->tool(ListMailboxMessagesTool::class, ['mailbox_id' => $this->mailbox->id]);

    $listing->assertOk()->assertSee(['Nieuw bericht', 'Oud bericht']);

    McpServer::actingAs($this->owner)->tool(ListMailboxMessagesTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'unread_only' => true,
    ])
        ->assertOk()
        ->assertSee('Nieuw bericht')
        ->assertDontSee('Oud bericht');

    McpServer::actingAs($this->owner)->tool(ListMailboxMessagesTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'search' => 'Pietje',
    ])
        ->assertOk()
        ->assertSee('Nieuw bericht')
        ->assertDontSee('Oud bericht');

    McpServer::actingAs($this->owner)->tool(ListMailboxMessagesTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'limit' => 1,
    ])
        ->assertOk()
        ->assertSee('Nieuw bericht')
        ->assertDontSee('Oud bericht');
});

it('resolves access for many mailboxes in a fixed number of queries', function (): void {
    foreach (range(1, 2) as $index) {
        $box = mcpMailboxFor(user(['name' => sprintf('Other %d', $index)]));
        mcpGrant($box, $this->owner, AccessLevel::Read, $this->owner);
    }

    McpServer::actingAs($this->owner)->tool(ListMailboxesTool::class, [])->assertOk();

    $countQueries = function (): int {
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        McpServer::actingAs($this->owner)->tool(ListMailboxesTool::class, [])->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $withThree = $countQueries();

    foreach (range(3, 6) as $index) {
        $box = mcpMailboxFor(user(['name' => sprintf('Other %d', $index)]));
        mcpGrant($box, $this->owner, AccessLevel::Read, $this->owner);
    }

    expect($countQueries())->toBe($withThree);
});

it('reads an uncached message as plain text without marking it read', function (): void {
    $message = mcpMessage($this->mailbox, 7);

    $email = (new Email)
        ->from('klant@example.com')
        ->to($this->mailbox->email)
        ->subject('Offerte website')
        ->html('<html><head><style>p { color: red; }</style></head><body><p>Hallo <b>team</b>,</p><script>alert(1)</script><p>Graag een offerte.</p></body></html>')
        ->attach('pdf-inhoud', 'offerte.pdf', 'application/pdf');

    remoteFolder($this->mailbox, 'INBOX')->setMessages([
        remoteMessage(7, [], $email),
    ]);

    McpServer::actingAs($this->owner)->tool(GetMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
    ])
        ->assertOk()
        ->assertSee(['Hallo team,', 'Graag een offerte.', 'offerte.pdf', '"body_truncated": false'])
        ->assertDontSee(['<p>', 'alert(1)', 'color: red']);

    expect($message->refresh())
        ->hasBody()->toBeTrue()
        ->is_seen->toBeFalse();
});

it('caps the body at the maximum length', function (): void {
    $message = mcpMessage($this->mailbox, 5, [
        'body_fetched_at' => now(),
        'body_text' => str_repeat('a', GetMailboxMessageTool::MAX_BODY_LENGTH + 50),
    ]);

    McpServer::actingAs($this->owner)->tool(GetMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
    ])
        ->assertOk()
        ->assertSee(sprintf('"body": "%s"', str_repeat('a', GetMailboxMessageTool::MAX_BODY_LENGTH)))
        ->assertSee('"body_truncated": true');
});

it('reports a message that left the server as gone and forgets it', function (): void {
    $message = mcpMessage($this->mailbox, 8);

    McpServer::actingAs($this->owner)->tool(GetMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
    ])->assertHasErrors(['no longer on the mail server']);

    expect(MailboxMessage::query()->whereKey($message->id)->exists())->toBeFalse();
});

it('answers a mail server failure with a generic error', function (): void {
    $message = mcpMessage($this->mailbox, 9);
    $this->connector->imapFailure = new ImapConnectionFailedException('imap.example.com refused the login');

    McpServer::actingAs($this->owner)->tool(GetMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
    ])
        ->assertHasErrors(['could not deliver this message'])
        ->assertDontSee('imap.example.com');

    McpServer::actingAs($this->owner)->tool(UpdateMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
        'action' => 'read',
    ])
        ->assertHasErrors(['refused this change'])
        ->assertDontSee('imap.example.com');

    expect($message->refresh()->is_seen)->toBeFalse()
        ->and(MailboxAuditEntry::query()->where('action', AuditAction::MarkedRead)->exists())->toBeFalse();
});

it('refuses to archive a message that is already archived', function (): void {
    $archive = folder($this->mailbox, 'Archive', FolderRole::Archive);
    $message = storedMessage($archive, 4);

    McpServer::actingAs($this->owner)->tool(UpdateMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
        'action' => 'archive',
    ])->assertHasErrors(['already in the archive']);

    expect(MailboxMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

it('lets a read grant read but not change a message', function (): void {
    $reader = user(['name' => 'Reader']);
    mcpGrant($this->mailbox, $reader, AccessLevel::Read, $this->owner);
    $message = mcpMessage($this->mailbox);

    McpServer::actingAs($reader)->tool(ListMailboxMessagesTool::class, ['mailbox_id' => $this->mailbox->id])
        ->assertOk()
        ->assertSee('Offerte website');

    McpServer::actingAs($reader)->tool(UpdateMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
        'action' => 'read',
    ])->assertHasErrors(['needs write access']);

    expect($message->refresh()->is_seen)->toBeFalse()
        ->and(remoteFolder($this->mailbox, 'INBOX')->operations)->toBe([])
        ->and(MailboxAuditEntry::query()->where('action', AuditAction::MarkedRead)->exists())->toBeFalse();
});

it('marks, flags and archives through the actions with the token owner in the audit trail', function (): void {
    $worker = user(['name' => 'Worker']);
    mcpGrant($this->mailbox, $worker, AccessLevel::Write, $this->owner);
    $message = mcpMessage($this->mailbox, 3);

    McpServer::actingAs($worker)->tool(UpdateMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
        'action' => 'read',
    ])
        ->assertOk()
        ->assertSee('"is_seen": true');

    McpServer::actingAs($worker)->tool(UpdateMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
        'action' => 'flag',
    ])
        ->assertOk()
        ->assertSee('"is_flagged": true');

    expect($message->refresh())
        ->is_seen->toBeTrue()
        ->is_flagged->toBeTrue()
        ->and($this->inbox->refresh()->unseen_count)->toBe(0)
        ->and(remoteFolder($this->mailbox, 'INBOX')->operations)->toBe([
            ['flag' => '\\Seen', 'operation' => '+', 'type' => 'flag', 'uids' => [3]],
            ['flag' => '\\Flagged', 'operation' => '+', 'type' => 'flag', 'uids' => [3]],
        ]);

    McpServer::actingAs($worker)->tool(UpdateMailboxMessageTool::class, [
        'mailbox_id' => $this->mailbox->id,
        'message_id' => $message->id,
        'action' => 'archive',
    ])
        ->assertOk()
        ->assertSee('"archived": true');

    expect(MailboxMessage::query()->whereKey($message->id)->exists())->toBeFalse()
        ->and(MailboxAuditEntry::query()->pluck('user_id', 'action')->all())->toMatchArray([
            AuditAction::MarkedRead->value => $worker->id,
            AuditAction::Flagged->value => $worker->id,
            AuditAction::Archived->value => $worker->id,
        ]);
});

it('refuses an unknown action', function (): void {
    $message = mcpMessage($this->mailbox);

    foreach ([
        'delete',
        'send',
        'reply',
        'forward',
    ] as $action) {
        McpServer::actingAs($this->owner)->tool(UpdateMailboxMessageTool::class, [
            'mailbox_id' => $this->mailbox->id,
            'message_id' => $message->id,
            'action' => $action,
        ])->assertHasErrors();
    }

    expect(MailboxMessage::query()->whereKey($message->id)->exists())->toBeTrue()
        ->and($this->connector->transport->sent)->toBe([]);
});

it('keeps the tool names and offers no action that sends, replies, forwards or deletes mail', function (): void {
    $names = collect(MailboxMcp::tools())
        ->map(fn (string $class): string => app($class)->name());

    expect($names->all())->toBe([
        'list-mailboxes-tool',
        'list-mailbox-messages-tool',
        'get-mailbox-message-tool',
        'update-mailbox-message-tool',
    ])
        ->and(app(UpdateMailboxMessageTool::class)->toArray()['inputSchema']['properties']['action']['enum'])->toBe([
            'read',
            'unread',
            'flag',
            'unflag',
            'archive',
        ]);
});

it('marks only the update tool as one that changes mail, and none as destructive', function (): void {
    $annotations = collect(MailboxMcp::tools())
        ->mapWithKeys(fn (string $class): array => [app($class)->name() => app($class)->toArray()['annotations']]);

    expect($annotations->all())->toBe([
        'list-mailboxes-tool' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'list-mailbox-messages-tool' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'get-mailbox-message-tool' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'update-mailbox-message-tool' => ['readOnlyHint' => false, 'destructiveHint' => false],
    ]);
});
