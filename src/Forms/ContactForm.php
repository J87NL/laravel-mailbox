<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Forms;

use Illuminate\Validation\Rule;
use J87NL\Mailbox\Models\Mailbox;
use J87NL\Mailbox\Models\MailboxContact;
use TranquilTools\FormBuilder\AbstractForm;
use TranquilTools\FormBuilder\Fields\Email;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;
use TranquilTools\FormBuilder\FormConfig;

class ContactForm extends AbstractForm
{
    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly string $action,
        private readonly ?MailboxContact $contact = null,
    ) {}

    public function configure(FormConfig $form): void
    {
        $form->action($this->action)
            ->method($this->contact ? 'PUT' : 'POST')
            ->class('space-y-4')
            ->columns(2);

        if ($this->contact) {
            $form->fill($this->contact->only([
                'email',
                'name',
                'notes',
                'organisation',
            ]));
        }
    }

    public function fields(): array
    {
        return [
            Text::make('name')
                ->label(__('mailbox::mailbox.name'))
                ->rules('nullable')
                ->maxLength(255),
            Email::make('email')
                ->label(__('mailbox::mailbox.address'))
                ->rules([
                    'required',
                    'email:rfc',
                    'max:255',
                    Rule::unique('mailbox_contacts', 'email')
                        ->where('mailbox_id', $this->mailbox->id)
                        ->ignore($this->contact?->id),
                ]),
            Text::make('organisation')
                ->label(__('mailbox::mailbox.organisation'))
                ->rules('nullable')
                ->maxLength(255),
            Textarea::make('notes')
                ->label(__('mailbox::mailbox.notes'))
                ->rules('nullable', 'string', 'max:5000')
                ->columnSpanFull(),
            Submit::make('save')
                ->columnSpanFull(),
        ];
    }
}
