<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Forms;

use J87NL\Mailbox\Models\Mailbox;
use TranquilTools\FormBuilder\AbstractForm;
use TranquilTools\FormBuilder\Fields\Email;
use TranquilTools\FormBuilder\Fields\Number;
use TranquilTools\FormBuilder\Fields\Password;
use TranquilTools\FormBuilder\Fields\Select;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Toggle;
use TranquilTools\FormBuilder\FormConfig;

class MailboxForm extends AbstractForm
{
    public function __construct(
        private readonly string $action,
        private readonly ?Mailbox $mailbox = null,
    ) {}

    public function configure(FormConfig $form): void
    {
        $form->action($this->action)
            ->method($this->mailbox ? 'PUT' : 'POST')
            ->columns(2);

        if ($this->mailbox) {
            $form->fill($this->mailbox->toArray());
        }
    }

    public function fields(): array
    {
        return [
            Text::make('name')->label(__('mailbox::mailbox.name'))->maxLength(100)->required(),
            Email::make('email')->label(__('mailbox::mailbox.address'))->rules('required', 'email:rfc'),
            Text::make('from_name')->label(__('mailbox::mailbox.from_name'))->rules('nullable', 'max:100'),
            Select::make('authentication')
                ->label(__('mailbox::mailbox.authentication'))
                ->options([
                    [
                        'id' => 'plain',
                        'name' => __('mailbox::mailbox.auth_plain'),
                    ],
                    [
                        'id' => 'oauth',
                        'name' => 'OAuth 2.0',
                    ],
                ])
                ->default('plain')
                ->rules('required', 'in:plain,oauth'),
            Text::make('imap_host')->label(__('mailbox::mailbox.imap_host'))->required(),
            Number::make('imap_port')->label(__('mailbox::mailbox.imap_port'))->default(993)->rules('required', 'integer', 'between:1,65535'),
            Select::make('imap_encryption')
                ->label(__('mailbox::mailbox.imap_encryption'))
                ->options([
                    [
                        'id' => 'ssl',
                        'name' => 'SSL/TLS',
                    ],
                    [
                        'id' => 'starttls',
                        'name' => 'STARTTLS',
                    ],
                ])
                ->default('ssl')
                ->rules('required', 'in:ssl,starttls'),
            Text::make('imap_username')->label(__('mailbox::mailbox.username'))->required(),
            $this->password('imap_password'),
            Text::make('smtp_host')->label(__('mailbox::mailbox.smtp_host'))->required(),
            Number::make('smtp_port')->label(__('mailbox::mailbox.smtp_port'))->default(587)->rules('required', 'integer', 'between:1,65535'),
            Text::make('smtp_username')->label(__('mailbox::mailbox.username'))->required(),
            $this->password('smtp_password'),
            Toggle::make('append_sent')
                ->label(__('mailbox::mailbox.append_sent'))
                ->help(__('mailbox::mailbox.append_sent_help'))
                ->default(true)
                ->rules('boolean'),
            Toggle::make('active')
                ->label(__('mailbox::mailbox.active'))
                ->default(true)
                ->rules('boolean'),
            Submit::make('save')->columnSpanFull(),
        ];
    }

    private function password(string $name): Password
    {
        $field = Password::make($name)
            ->label(__('mailbox::mailbox.password'))
            ->rules($this->mailbox ? 'nullable' : 'required_unless:authentication,oauth');

        if (! $this->mailbox) {
            return $field;
        }

        return $field->help(__('mailbox::mailbox.password_keep'));
    }
}
