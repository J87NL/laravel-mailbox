<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Forms;

use DirectoryTree\ImapEngine\Support\Str;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxFolder;
use TranquilTools\FormBuilder\AbstractForm;
use TranquilTools\FormBuilder\Fields\Select;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\FormConfig;

class FolderForm extends AbstractForm
{
    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly string $action,
    ) {}

    public function configure(FormConfig $form): void
    {
        $form->action($this->action)
            ->method('POST')
            ->class('space-y-3')
            ->resetOnSuccess();
    }

    public function fields(): array
    {
        return [
            Text::make('name')
                ->label(__('mailbox::mailbox.folder_name'))
                ->maxLength(100)
                ->rules('required', 'not_regex:/[\/\\\\*%]/'),
            Select::make('parent_id')
                ->label(__('mailbox::mailbox.folder_parent'))
                ->options($this->parentOptions())
                ->optionLabel('path')
                ->optionValue('id')
                ->rules('nullable', 'exists:mailbox_folders,id'),
            Submit::make('create')
                ->label(__('mailbox::mailbox.folder_create')),
        ];
    }

    /**
     * @return array<int, array{id: int, path: string}>
     */
    private function parentOptions(): array
    {
        return $this->mailbox->loadMissing('folders')->folders
            ->sortBy('path')
            ->map(fn (MailboxFolder $folder): array => [
                'id' => $folder->id,
                'path' => Str::fromImapUtf7($folder->path),
            ])
            ->values()
            ->all();
    }
}
