<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use J87NL\Mailbox\MailboxServiceProvider;
use J87NL\Mailbox\Testing\FakeConnector;
use J87NL\Mailbox\Tests\Support\Group;
use J87NL\Mailbox\Tests\Support\User;
use Orchestra\Testbench\TestCase as Orchestra;
use TranquilTools\FormBuilder\FormBuilderServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public FakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->connector = FakeConnector::install();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            FormBuilderServiceProvider::class,
            MailboxServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mailbox.user_model', User::class);
        $app['config']->set('mailbox.access.groups', [
            [
                'label' => 'name',
                'model' => Group::class,
                'prefix' => 'Group',
                'relation' => 'groups',
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('group_user', function (Blueprint $table): void {
            $table->foreignId('group_id');
            $table->foreignId('user_id');
        });

        (include __DIR__.'/../database/migrations/create_mailbox_tables.php.stub')->up();
    }
}
