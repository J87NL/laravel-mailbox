<?php

declare(strict_types=1);

namespace J87NL\Mailbox;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use J87NL\Mailbox\Access\MailboxAccessResolver;
use J87NL\Mailbox\Commands\AddMailboxCommand;
use J87NL\Mailbox\Commands\GrantAccessCommand;
use J87NL\Mailbox\Commands\PruneBodiesCommand;
use J87NL\Mailbox\Commands\SyncCommand;
use J87NL\Mailbox\Connection\MailboxConnector;
use J87NL\Mailbox\Contracts\ResolvesAccessors;
use J87NL\Mailbox\Enums\AccessLevel;
use J87NL\Mailbox\Models\Mailbox;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MailboxServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('mailbox')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigrations([
                'create_mailbox_tables',
                'add_signature_to_mailboxes_table',
                'create_mailbox_contacts_table',
                'add_sent_at_index_to_mailbox_messages_table',
            ])
            ->hasCommands([
                AddMailboxCommand::class,
                GrantAccessCommand::class,
                PruneBodiesCommand::class,
                SyncCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(MailboxConnector::class);
        $this->app->scoped(MailboxAccessResolver::class);
        $this->app->scoped(ResolvesAccessors::class, fn (): ResolvesAccessors => $this->app->make(config('mailbox.access.resolver')));
    }

    public function packageBooted(): void
    {
        $this->app->booted(function (): void {
            self::defineAbilities();
        });
    }

    public static function defineAbilities(): void
    {
        $levels = [
            'accessMailbox' => AccessLevel::Read,
            'manageMailbox' => AccessLevel::Manage,
            'workInMailbox' => AccessLevel::Write,
        ];

        foreach ($levels as $ability => $level) {
            self::defineUnlessDefined($ability, fn (?Authenticatable $user, Mailbox $mailbox): bool => app(MailboxAccessResolver::class)->allows($user, $mailbox, $level));
        }

        self::defineUnlessDefined('accessAnyMailbox', fn (?Authenticatable $user): bool => app(MailboxAccessResolver::class)->allowsAny($user));
        self::defineUnlessDefined('manageMailboxes', fn (?Authenticatable $user): bool => app(MailboxAccessResolver::class)->isAdmin($user));
    }

    private static function defineUnlessDefined(string $ability, Closure $callback): void
    {
        if (Gate::has($ability)) {
            return;
        }

        Gate::define($ability, $callback);
    }
}
