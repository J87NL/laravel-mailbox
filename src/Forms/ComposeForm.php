<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Forms;

use J87NL\Mailbox\Rules\EmailList;
use TranquilTools\FormBuilder\AbstractForm;
use TranquilTools\FormBuilder\Fields\Files;
use TranquilTools\FormBuilder\Fields\Hidden;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Wysiwyg;
use TranquilTools\FormBuilder\FormConfig;

class ComposeForm extends AbstractForm
{
    public function __construct(
        private readonly string $action,
        private readonly array $defaults = [],
    ) {}

    public function configure(FormConfig $form): void
    {
        $form->action($this->action)
            ->method('POST')
            ->fill($this->defaults);
    }

    public function fields(): array
    {
        return [
            Hidden::make('reply_to_message')
                ->rules('nullable', 'integer'),
            Text::make('to')
                ->label(__('mailbox::mailbox.to'))
                ->placeholder('name@example.com, other@example.com')
                ->rules(['required', new EmailList]),
            Text::make('cc')
                ->label(__('mailbox::mailbox.cc'))
                ->rules(['nullable', new EmailList]),
            Text::make('bcc')
                ->label(__('mailbox::mailbox.bcc'))
                ->rules(['nullable', new EmailList]),
            Text::make('subject')
                ->label(__('mailbox::mailbox.subject'))
                ->maxLength(250)
                ->required(),
            $this->body(),
            Files::make('attachments')
                ->label(__('mailbox::mailbox.attachments'))
                ->rules('nullable', 'array'),
            Submit::make('send')
                ->label(__('mailbox::mailbox.send')),
        ];
    }

    private function body(): Wysiwyg
    {
        $field = Wysiwyg::make('body')
            ->label(__('mailbox::mailbox.body'))
            ->required();

        $editor = (string) config('mailbox.compose.editor');

        $field->editor($editor);

        $options = config('mailbox.compose.editor_options.'.$editor);

        if (! is_array($options)) {
            return $field;
        }

        return $field->options($options);
    }
}
