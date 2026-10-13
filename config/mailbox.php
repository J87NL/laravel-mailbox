<?php

declare(strict_types=1);
use J87NL\Mailbox\Access\RelationAccessorResolver;

return [

    'user_model' => env('MAILBOX_USER_MODEL', 'App\\Models\\User'),

    'access' => [
        'mode' => env('MAILBOX_ACCESS_MODE', 'restricted'),
        'admins' => array_filter(explode(',', (string) env('MAILBOX_ADMINS', ''))),
        'resolver' => RelationAccessorResolver::class,
        'user_label' => 'name',
        'groups' => [],
    ],

    'compose' => [
        'editor' => env('MAILBOX_COMPOSE_EDITOR', 'hugerte'),
        'editor_options' => [
            'hugerte' => [
                'menubar' => false,
                'statusbar' => false,
                'plugins' => 'autolink link lists',
                'toolbar' => 'bold italic underline | bullist numlist blockquote | link | removeformat',
                'convert_urls' => false,
                'entity_encoding' => 'raw',
                'paste_data_images' => false,
                'min_height' => 320,
            ],
            'quill' => [
                'theme' => 'snow',
                'modules' => [
                    'toolbar' => [
                        ['bold', 'italic', 'underline'],
                        ['blockquote', ['list' => 'ordered'], ['list' => 'bullet']],
                        ['link'],
                        ['clean'],
                    ],
                ],
            ],
        ],
    ],

    'sync' => [
        'chunk_size' => 50,
        'flag_window' => 500,
        'initial_days' => 90,
        'queue' => env('MAILBOX_SYNC_QUEUE'),
    ],

    'folders' => [
        'archive_fallback' => 'Archive',
        'trash_fallback' => 'Trash',
    ],

    'html' => [
        'block_remote_images' => true,
    ],

    'oauth_token_provider' => null,

];
