<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Forms;

use J87NL\Mailbox\Models\MailboxTemplate;
use TranquilTools\FormBuilder\AbstractForm;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\FormConfig;

class TemplateForm extends AbstractForm
{
    public function __construct(
        private readonly string $action,
        private readonly ?MailboxTemplate $template = null,
    ) {}

    public function configure(FormConfig $form): void
    {
        $form->action($this->action)
            ->method($this->template ? 'PUT' : 'POST')
            ->class('space-y-4');

        if ($this->template) {
            $form->fill($this->template->only([
                'body',
                'name',
                'subject',
            ]));
        }
    }

    public function fields(): array
    {
        return [
            Text::make('name')
                ->label(__('mailbox::mailbox.template_name'))
                ->maxLength(100)
                ->required(),
            Text::make('subject')
                ->label(__('mailbox::mailbox.subject'))
                ->help(__('mailbox::mailbox.template_subject_help'))
                ->rules('nullable', 'string')
                ->maxLength(250),
            EmailEditor::make('body')
                ->label(__('mailbox::mailbox.body'))
                ->help(__('mailbox::mailbox.template_placeholders'))
                ->rules('required', 'string', 'max:50000'),
            Submit::make('save'),
        ];
    }
}
