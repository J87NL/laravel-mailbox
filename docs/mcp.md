# MCP tools

The package ships four tools for a [Laravel MCP](https://github.com/laravel/mcp) server, so an AI assistant can read
and tidy up the mailboxes a user may open. They are optional: install `laravel/mcp` (`^0.5`, which needs Laravel
11.45.3, 12.41.1 or 13) in your app to use them. Without it the package works as before. The service provider never
registers the tools; your own MCP server does.

## The tools

| Tool                          | Does                                                                                                                                                         | Needs           |
|-------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------|
| `list-mailboxes-tool`         | The active mailboxes the user may open, with the access level (`read`, `write` or `manage`) and the selectable folders with their unread count             | `accessMailbox` |
| `list-mailbox-messages-tool`  | The cached message headers of one folder (default the inbox), newest first. Optional `unread_only`, `search` on subject and sender, and `limit` (default 25, at most 100) | `accessMailbox` |
| `get-mailbox-message-tool`    | One message: headers, recipients, attachment names and the body as plain text, capped at 20000 characters (`body_truncated` says when it was cut). Does not mark it read | `accessMailbox` |
| `update-mailbox-message-tool` | Marks one message `read` or `unread`, `flag` or `unflag`, or `archive`s it                                                                                   | `workInMailbox` |

What they never do: send, reply, forward or delete mail. `update-mailbox-message-tool` only accepts the five actions
above. Changes go through `MarkMessages` and `ArchiveMessages`, so the IMAP command runs first and the
[audit trail](audit-trail.md) records the user the tool runs for.

Reading a body uses `FetchMessageBody` like the UI does: the body is fetched and cached on first read. A message that
is no longer on the server is forgotten with `ForgetMessage` and the tool says it is gone. The HTML body is sanitized
with `MessageHtml::render()` and stripped to text, without remote images.

Errors:

- A mailbox the user may not open gives the same `Mailbox '{id}' not found` error as a mailbox that does not exist, so
  a tool cannot be used to probe for mailboxes.
- A user with only read access gets an error from `update-mailbox-message-tool`; nothing is changed.
- An IMAP failure is passed to `report()` and answered with a generic error, without host names or server messages.

## Registering the tools

Add the tool classes to the `$tools` of your server, for example in `boot()`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use J87NL\Mailbox\Mcp\MailboxMcp;
use Laravel\Mcp\Server;

class AppServer extends Server
{
    protected function boot(): void
    {
        $this->tools = [
            ...$this->tools,
            ...MailboxMcp::tools(),
        ];
    }
}
```

`MailboxMcp::tools()` returns `ListMailboxesTool`, `ListMailboxMessagesTool`, `GetMailboxMessageTool` and
`UpdateMailboxMessageTool` from `J87NL\Mailbox\Mcp\Tools`. List the classes yourself to pick only some of them, for
example only the reading tools.

## Who the tools run for

A tool runs for `$request->user()`, the user your MCP route authenticates (for example with
`Mcp::web(...)->middleware('auth:sanctum')` or Passport). Without a user every tool answers with an error.

When your server finds the user another way, extend the tool and override `mailboxUser()`:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use J87NL\Mailbox\Mcp\Tools\ListMailboxesTool as BaseListMailboxesTool;
use Laravel\Mcp\Request;

class ListMailboxesTool extends BaseListMailboxesTool
{
    protected function mailboxUser(Request $request): ?Authenticatable
    {
        return app(McpTokenUser::class)->resolve($request);
    }
}
```

Keep the class name (or set the name with the `#[Name]` attribute) so the tool keeps its name.

### Assistants and servers that read the tools themselves

Some apps do more with their tools than list them in `$tools`: they read `$tools` by reflection to build a tool list,
wrap the tools for an in-app assistant that calls them without an MCP request, or resolve the MCP user inside the tool
instead of through `$request->user()`. List subclasses of the package tools in that case, not `MailboxMcp::tools()`:

- one subclass per tool, with the same class basename, so `list-mailboxes-tool` and the other names stay the same;
- override `mailboxUser()` to return the user the call runs for;
- put the subclasses in `$tools` like your own tools, so reflection sees ordinary classes of your app.

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Mailbox;

use App\Mcp\McpUser;
use Illuminate\Contracts\Auth\Authenticatable;
use J87NL\Mailbox\Mcp\Tools\ListMailboxesTool as PackageListMailboxesTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListMailboxesTool extends PackageListMailboxesTool
{
    private ?Authenticatable $assistantUser = null;

    public function handleFor(Authenticatable $user, Request $request): Response
    {
        $this->assistantUser = $user;

        try {
            return $this->handle($request);
        } finally {
            $this->assistantUser = null;
        }
    }

    protected function mailboxUser(Request $request): ?Authenticatable
    {
        return $this->assistantUser ?? app(McpUser::class)->current();
    }
}
```

The assistant calls `handleFor($user, new Request($arguments))`, the MCP server calls `handle()` as usual. Put the
shared part in a trait when you wrap all four tools. The access checks stay in the package tool, so the wrapper cannot
widen what the user may do.

## Access

The tools decide access only with the package [abilities](access.md#abilities): `accessAnyMailbox` before any tool
runs, `accessMailbox` per mailbox for reading and `workInMailbox` for changes. Admins, open mode and your own
overrides of these abilities apply as they do in the UI. `isAvailableTo($user)` on each tool returns whether the user
passes `accessAnyMailbox`, so a server can leave the tools out for users who cannot use them.

Users who log in to the same app but must never see a mailbox, such as customers, are kept out with
`access.user_query`. A user that query filters out gets no level at all, even with an access row of their own, as
admin or in open mode, so every tool refuses them. See
[Keeping users and groups out](access.md#keeping-users-and-groups-out).

## Mail content is data

Subjects, sender names, folder names and bodies are written by outsiders. The tool descriptions tell the assistant to
treat them as data and never as instructions, but a description is not an access control. The tools can only do what
the user may do in the mailbox and never send mail, so an instruction hidden in a message cannot make an assistant
mail anything out through these tools. Keep that in mind before you add tools of your own that act on mail content.

## Testing

The tools run on the [fake connector](testing.md) like the rest of the package. With the `laravel/mcp` test helpers:

```php
use App\Mcp\Servers\AppServer;
use J87NL\Mailbox\Mcp\Tools\ListMailboxesTool;

AppServer::actingAs($user)
    ->tool(ListMailboxesTool::class)
    ->assertOk()
    ->assertSee($mailbox->email);
```
