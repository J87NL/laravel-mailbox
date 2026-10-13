<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Commands;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use J87NL\Mailbox\Actions\GrantAccess;
use J87NL\Mailbox\Contracts\ResolvesAccessors;
use J87NL\Mailbox\Enums\AccessLevel;
use J87NL\Mailbox\Models\Mailbox;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

class GrantAccessCommand extends Command
{
    protected $signature = 'mailbox:grant {mailbox : Mailbox id}';

    protected $description = 'Grant a user or group access to a shared mailbox';

    public function handle(GrantAccess $grant, ResolvesAccessors $accessors): int
    {
        $mailbox = Mailbox::query()
            ->findOrFail((int) $this->argument('mailbox'));
        $options = collect($accessors->assignable())->pluck('label', 'key');

        $key = search(
            'User or group',
            fn (string $value): array => $options
                ->filter(fn (string $label): bool => str_contains(mb_strtolower($label), mb_strtolower($value)))
                ->all(),
        );

        $level = AccessLevel::from(select('Level', [
            AccessLevel::Read->value => AccessLevel::Read->label(),
            AccessLevel::Write->value => AccessLevel::Write->label(),
            AccessLevel::Manage->value => AccessLevel::Manage->label(),
        ]));

        [$type, $id] = explode(':', (string) $key, 2);

        try {
            $grant->handle($mailbox, $type, $id, $level, null);
        } catch (ValidationException $exception) {
            $this->components->error(implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        }

        $this->components->info("{$options[$key]} now has {$level->value} access to [{$mailbox->name}].");

        return self::SUCCESS;
    }
}
