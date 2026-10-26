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

    'cache' => [
        'body_days' => env('MAILBOX_BODY_CACHE_DAYS', 30),
        'raw_disk' => env('MAILBOX_RAW_CACHE_DISK', 'local'),
        'raw_hours' => env('MAILBOX_RAW_CACHE_HOURS', 24),
    ],

    'compose' => [
        'editor' => env('MAILBOX_COMPOSE_EDITOR', 'hugerte'),
        'editor_options' => [
            'hugerte' => [
                'menubar' => false,
                'statusbar' => false,
                'convert_urls' => false,
                'entity_encoding' => 'raw',
                'paste_data_images' => false,
                'min_height' => 320,
                'extended_valid_elements' => 'img[src|alt|title|width|height|style|class|data-mailbox-cid]',
            ],
        ],
    ],

    'contacts' => [
        'providers' => [],
        'scanned_messages' => 500,
    ],

    'connection' => [
        'read_timeout' => (int) env('MAILBOX_CONNECTION_READ_TIMEOUT', 15),
        'timeout' => (int) env('MAILBOX_CONNECTION_TIMEOUT', 10),
    ],

    'display_timezone' => env('MAILBOX_DISPLAY_TIMEZONE'),

    'sync' => [
        'chunk_size' => 50,
        'flag_chunk_size' => 100,
        'flag_window' => 500,
        'initial_days' => 90,
        'job_timeout_seconds' => (int) env('MAILBOX_SYNC_JOB_TIMEOUT', 55),
        'queue' => env('MAILBOX_SYNC_QUEUE'),
        'skip_special_use' => [
            '\\All',
            '\\Important',
            '\\Flagged',
        ],
        'step_reserve_seconds' => (int) env('MAILBOX_SYNC_STEP_RESERVE', 5),
        'time_budget_seconds' => (int) env('MAILBOX_SYNC_TIME_BUDGET', 40),
    ],

    'folders' => [
        'archive_fallback' => 'Archive',
        'trash_fallback' => 'Trash',
    ],

    'threading' => [
        'subject_days' => 90,
    ],

    'html' => [
        'block_remote_images' => true,
    ],

    'oauth_token_provider' => null,

];
