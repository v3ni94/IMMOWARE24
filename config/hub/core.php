<?php

declare(strict_types=1);

/*
 * Zentrale Hub-Konfiguration. Variablennamen gemäß .env.example und
 * docs/immoware/05-write-capabilities.md Abschnitt 2.1. Standard ist überall false.
 */
return [

    // BootGuard prüft beim Start die hart gesperrten Schreib-Flags. Abschaltbar nur in Tests.
    'boot_guard' => (bool) env('HUB_BOOT_GUARD', true),

    'encryption' => [
        'key_provider' => env('HUB_ENCRYPTION_KEY_PROVIDER'),
        'key_id' => env('HUB_ENCRYPTION_KEY_ID'),
    ],

    'read' => [
        'enabled' => (bool) env('IMMOWARE_READ_ENABLED', true),
        'rate_limit_rps' => (float) env('IMMOWARE_RATE_LIMIT_RPS', 2),
        'max_concurrency' => (int) env('IMMOWARE_MAX_CONCURRENCY_READ', 2),
    ],

    'write' => [
        // Globaler Hauptschalter für den WriteOperationService.
        'enabled' => (bool) env('IMMOWARE_WRITE_ENABLED', false),
        // Einzige Operation, die je auf true stehen darf: create-only PUT in den Posteingang.
        'webdav_create_enabled' => (bool) env('IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED', false),
        // Fest verdrahtet gesperrt. true verhindert den Start (BootGuard).
        'webdav_overwrite_enabled' => (bool) env('IMMOWARE_WRITE_WEBDAV_OVERWRITE_ENABLED', false),
        'webdav_delete_enabled' => (bool) env('IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED', false),
        'webdav_move_enabled' => (bool) env('IMMOWARE_WRITE_WEBDAV_MOVE_ENABLED', false),
        'carddav_enabled' => (bool) env('IMMOWARE_WRITE_CARDDAV_ENABLED', false),
        'caldav_enabled' => (bool) env('IMMOWARE_WRITE_CALDAV_ENABLED', false),

        'allowed_prefix' => env('IMMOWARE_WRITE_ALLOWED_PREFIX', '/Posteingang/'),
        'max_upload_bytes' => (int) env('IMMOWARE_WRITE_MAX_UPLOAD_BYTES', 26214400),
        'verify_with_hash' => (bool) env('IMMOWARE_WRITE_VERIFY_WITH_HASH', true),
        'unknown_propfind_attempts' => (int) env('IMMOWARE_WRITE_UNKNOWN_PROPFIND_ATTEMPTS', 3),
        'unknown_propfind_interval_seconds' => (int) env('IMMOWARE_WRITE_UNKNOWN_PROPFIND_INTERVAL_SECONDS', 300),
        'max_concurrency' => (int) env('IMMOWARE_WRITE_MAX_CONCURRENCY', 1),
        'dry_run' => (bool) env('IMMOWARE_WRITE_DRY_RUN', false),
    ],

    'imports' => [
        'drop_path' => env('HUB_IMPORT_DROP_PATH'),
        'require_metadata' => (bool) env('HUB_IMPORT_REQUIRE_METADATA', true),
    ],

    'webhooks' => [
        'enabled' => (bool) env('HUB_WEBHOOKS_ENABLED', false),
        'replay_window_seconds' => (int) env('HUB_WEBHOOK_REPLAY_WINDOW_SECONDS', 300),
    ],

    'api_keys' => [
        'enabled' => (bool) env('HUB_API_KEYS_ENABLED', false),
    ],

    // Queue-Namen gemäß CLAUDE.md
    'queues' => [
        'high' => 'high',
        'default' => 'default',
        'sync' => 'sync',
        'write' => 'write',
        'documents' => 'documents',
        'low' => 'low',
    ],

    // Backoff für Jobs in Sekunden
    'job_backoff' => [30, 120, 600, 1800],
];
