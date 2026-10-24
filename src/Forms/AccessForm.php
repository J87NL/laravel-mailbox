<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Forms;

use J87NL\Mailbox\Enums\AccessLevel;
use J87NL\Mailbox\Rules\ExistingAccessor;
use TranquilTools\FormBuilder\AbstractForm;
use TranquilTools\FormBuilder\Fields\Select;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\FormConfig;

class AccessForm extends AbstractForm
{
    public const int MIN_SEARCH_LENGTH = 2;

    public function __construct(
        private readonly string $action,
        private readonly string $searchUrl,
    ) {}

    public function configure(FormConfig $form): void
    {
        $form->action($this->action)->method('POST')->resetOnSuccess()->columns(2);
    }

    public function fields(): array
    {
        return [
            Select::make('accessor')
                ->label(__('mailbox::mailbox.accessor'))
                ->searchUrl($this->searchUrl)
                ->minSearchLength(self::MIN_SEARCH_LENGTH)
                ->rules('required', 'string', new ExistingAccessor),
            Select::make('level')
                ->label(__('mailbox::mailbox.level'))
                ->options(array_map(fn (AccessLevel $level): array => [
                    'id' => $level->value,
                    'name' => $level->label(),
                ], [
                    AccessLevel::Read,
                    AccessLevel::Write,
                    AccessLevel::Manage,
                ]))
                ->default(AccessLevel::Read->value)
                ->rules('required', 'in:read,write,manage'),
            Submit::make('grant')->label(__('mailbox::mailbox.grant'))->columnSpanFull(),
        ];
    }
}
