<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Forms;

use Illuminate\Http\Request;
use TranquilTools\FormBuilder\AbstractForm;
use TranquilTools\FormBuilder\Fields\Files;
use TranquilTools\FormBuilder\Fields\Hidden;
use TranquilTools\FormBuilder\Fields\Submit;
use TranquilTools\FormBuilder\Fields\Tags;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\FormConfig;

class ComposeForm extends AbstractForm
{
    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<int, array{key: string, name: string, size?: int, url?: string}>  $forwardedAttachments
     */
    public function __construct(
        private readonly string $action,
        private readonly array $defaults = [],
        private readonly ?string $suggestionsUrl = null,
        private readonly array $forwardedAttachments = [],
    ) {}

    public function configure(FormConfig $form): void
    {
        $form->action($this->action)
            ->method('POST')
            ->class('space-y-4')
            ->fill($this->defaults);
    }

    public function fields(): array
    {
        return [
            Hidden::make('reply_to_message')
                ->rules('nullable', 'integer'),
            Hidden::make('forward_message')
                ->rules('nullable', 'integer'),
            $this->recipients('to')
                ->required(),
            $this->recipients('cc'),
            $this->recipients('bcc'),
            Text::make('subject')
                ->label(__('mailbox::mailbox.subject'))
                ->maxLength(250)
                ->required(),
            EmailEditor::make('body')
                ->label(__('mailbox::mailbox.body'))
                ->required(),
            $this->attachments(),
            Submit::make('send')
                ->label(__('mailbox::mailbox.send')),
        ];
    }

    /**
     * @return list<string>
     */
    public function keptForwardedAttachments(?Request $request = null): array
    {
        $removed = $this->attachments()->removedKeys($request);

        return array_values(array_filter(
            array_column($this->forwardedAttachments, 'key'),
            fn (string $key): bool => ! in_array($key, $removed, true),
        ));
    }

    private function recipients(string $name): Tags
    {
        return Tags::make($name)
            ->label(__(sprintf('mailbox::mailbox.%s', $name)))
            ->emails()
            ->max(100)
            ->suggestions($this->suggestionsUrl);
    }

    private function attachments(): Files
    {
        $field = Files::make('attachments')
            ->label(__('mailbox::mailbox.attachments'))
            ->rules('nullable', 'array');

        if ($this->forwardedAttachments === []) {
            return $field;
        }

        return $field
            ->current($this->forwardedAttachments)
            ->removable();
    }
}
