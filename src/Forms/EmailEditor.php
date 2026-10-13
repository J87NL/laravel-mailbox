<?php

declare(strict_types=1);

namespace J87NL\Mailbox\Forms;

use TranquilTools\FormBuilder\Fields\Wysiwyg;

class EmailEditor
{
    public static function make(string $name): Wysiwyg
    {
        $editor = (string) config('mailbox.compose.editor');

        $field = Wysiwyg::make($name)
            ->editor($editor)
            ->profile('email');

        $options = config(sprintf('mailbox.compose.editor_options.%s', $editor));

        if (! is_array($options) || $options === []) {
            return $field;
        }

        return $field->options($options);
    }
}
