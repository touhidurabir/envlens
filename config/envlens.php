<?php

declare(strict_types=1);

return [
    'files' => ['source' => '.env', 'example' => '.env.example'],
    // A config key read without a default (or with null) is only "required" when it is listed
    // in the example file or matches a glob here; otherwise a missing value is just informational.
    'required' => [],
    'sensitive' => [
        'keys' => ['APP_KEY'],
        'patterns' => ['*_KEY', '*_SECRET', '*_SECRET_*', '*_PASSWORD', '*_PASS', '*_TOKEN', '*_PRIVATE*', '*_DSN', '*_CREDENTIALS*', '*_SALT', '*_PASSPHRASE', '*_PWD', '*_AUTH', '*_WEBHOOK*'],
        'except' => ['*_PUBLIC_KEY', 'VITE_*_KEY'],
    ],
    'scan' => [
        'config_paths' => ['config'], // relative to the project base path, or absolute
        'code_paths' => ['app', 'routes', 'database', 'resources/views', 'bootstrap'],
        'exclude' => ['vendor', 'storage', 'node_modules', 'bootstrap/cache'],
        'allow_env_outside_config' => [], // globs of relative file paths
    ],
    'cleanup' => ['keep' => [
        'VITE_*', 'SAIL_*', 'WWWUSER', 'WWWGROUP', 'FORWARD_*', 'PHP_CLI_SERVER_WORKERS',
        'APP_RUNNING_IN_CONSOLE', 'APP_*_CACHE', 'APP_BASE_PATH', 'LARAVEL_*',
    ]],
    'write' => [
        'backup' => true,
        // Relative to the project base path, or absolute. Created 0700 with a .gitignore that ignores it.
        'backup_path' => 'storage/envlens/backups',
        'group_by_prefix' => true,
        'header_comment' => true,
        'remove_attached_comments' => true,
    ],
    // Keys that never get a config-default placeholder in env:generate / env:sync.
    'generate' => ['skip_defaults' => ['APP_ENV', 'APP_DEBUG']],
    'production_environments' => ['production'],
];
