<?php

declare(strict_types=1);

namespace J87NL\Mailbox;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use J87NL\Mailbox\Access\MailboxAccessResolver;
use J87NL\Mailbox\Commands\AddMailboxCommand;
use J87NL\Mailbox\Commands\GrantAccessCommand;
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
            ->hasMigration('create_mailbox_tables')
            ->hasCommands([AddMailboxCommand::class, GrantAccessCommand::class, SyncCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(MailboxConnector::class);
        $this->app->scoped(MailboxAccessResolver::class);
        $this->app->bind(ResolvesAccessors::class, fn () => $this->app->make(config('mailbox.access.resolver')));
    }

    public function packageBooted(): void
    {
        $this->app->booted(function (): void {
            $abilities = [
                'accessMailbox' => AccessLevel::Read,
                'manageMailbox' => AccessLevel::Manage,
                'workInMailbox' => AccessLevel::Write,
            ];

            foreach ($abilities as $ability => $level) {
                if (Gate::has($ability)) {
                    continue;
                }

                Gate::define($ability, fn (?Authenticatable $user, Mailbox $mailbox): bool => app(MailboxAccessResolver::class)->allows($user, $mailbox, $level));
            }

            if (Gate::has('manageMailboxes')) {
                return;
            }

            Gate::define('manageMailboxes', fn (?Authenticatable $user): bool => app(MailboxAccessResolver::class)->isAdmin($user));
        });
    }
}
