<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Sync. Zugriff über config('hub.sync.*').
 * Intervalle folgen docs/immoware/07-sync-strategy.md Abschnitt 1.5 bzw. der Aufgabenvorgabe.
 */
return [

    'queue' => env('HUB_SYNC_QUEUE', 'sync'),

    'jobs' => [
        'tries' => (int) env('HUB_SYNC_JOB_TRIES', 5),
        'timeout_seconds' => (int) env('HUB_SYNC_JOB_TIMEOUT', 900),
        // Basiswartezeiten je Versuch in Sekunden (07-sync-strategy.md Abschnitt 6.1).
        'backoff' => [30, 120, 600, 1800],
        // Maximaler Jitter je Stufe in Sekunden.
        'jitter' => [10, 30, 60, 120],
        'jitter_enabled' => (bool) env('HUB_SYNC_JITTER_ENABLED', true),
    ],

    'chunks' => [
        // Datensätze je pull()-Aufruf.
        'limit' => (int) env('HUB_SYNC_CHUNK_LIMIT', 500),
        // Chunks je Job-Ausführung; danach Re-Dispatch mit Cursor (kein Endloslauf).
        'max_per_run' => (int) env('HUB_SYNC_MAX_CHUNKS_PER_RUN', 20),
    ],

    'locks' => [
        // TTL des Full-Sync-Locks in Sekunden (mindestens 30 Minuten laut Konzept).
        'full_ttl_seconds' => (int) env('HUB_SYNC_FULL_LOCK_TTL', 7200),
        'overlap_ttl_seconds' => (int) env('HUB_SYNC_OVERLAP_TTL', 3600),
    ],

    // Schwellen in Sekunden, nach denen ein Entitätsstand als veraltet gilt (stale_since).
    'stale_after_seconds' => [
        'default' => (int) env('HUB_SYNC_STALE_DEFAULT', 86400),
        'contact' => (int) env('HUB_SYNC_STALE_CONTACT', 7200),
        'document' => (int) env('HUB_SYNC_STALE_DOCUMENT', 7200),
        'calendar_event' => (int) env('HUB_SYNC_STALE_CALENDAR', 28800),
    ],

    'payloads' => [
        'disk' => env('HUB_SYNC_PAYLOAD_DISK', 'local'),
        'path_prefix' => 'sync/payloads',
        'inline_limit_bytes' => 65536,
        'retention_days' => (int) env('HUB_SYNC_PAYLOAD_RETENTION_DAYS', 90),
        'compress' => true,
    ],

    'dlq' => [
        'trace_max_chars' => 8000,
        'retention_days' => (int) env('HUB_SYNC_DLQ_RETENTION_DAYS', 180),
    ],

    'schedule' => [
        'enabled' => (bool) env('HUB_SYNC_SCHEDULE_ENABLED', true),
        'timezone' => 'Europe/Berlin',
        'contact' => env('HUB_SYNC_CRON_CONTACTS', '*/5 * * * *'),
        'document' => env('HUB_SYNC_CRON_DOCUMENTS', '*/5 * * * *'),
        'calendar_event' => env('HUB_SYNC_CRON_CALENDAR', '*/15 * * * *'),
        'full' => env('HUB_SYNC_CRON_FULL', '30 2 * * *'),
        'stale_check' => env('HUB_SYNC_CRON_STALE', '*/10 * * * *'),
        'payload_prune' => env('HUB_SYNC_CRON_PAYLOAD_PRUNE', '15 4 * * *'),
    ],

    'bootstrap' => [
        'stages' => [1, 10, 100, 1000, null],
        // Abbruch, wenn failed / processed diese Quote überschreitet.
        'max_error_rate' => (float) env('HUB_SYNC_BOOTSTRAP_MAX_ERROR_RATE', 0.05),
    ],

    'metrics' => [
        'cache_prefix' => 'hub:sync:metrics',
        'ttl_seconds' => 86400 * 7,
    ],
];
