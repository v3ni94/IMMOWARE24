<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Api. Zugriff über config('hub.api.*').
 * Grundlage: docs/immoware/09-api-documentation.md.
 */
return [

    'version' => 'v1',

    // Basis der type-URLs im Fehlerformat RFC 7807.
    'problem_base_url' => env('HUB_API_PROBLEM_BASE_URL', 'https://immoware.muellerhv.de/errors/'),

    'base_url' => env('HUB_API_BASE_URL', 'https://immoware.muellerhv.de'),

    'pagination' => [
        'default_per_page' => 100,
        'max_per_page' => 500,
    ],

    'rate_limits' => [
        // Requests pro Minute je API-Key.
        'read_per_minute' => (int) env('HUB_API_RATE_LIMIT_READ', 600),
        'write_per_minute' => (int) env('HUB_API_RATE_LIMIT_WRITE', 60),
    ],

    'idempotency' => [
        'ttl_hours' => 24,
        'header' => 'Idempotency-Key',
    ],

    'health' => [
        // Ab dieser Queue-Tiefe gilt die Queue als degraded.
        'queue_depth_warning' => (int) env('HUB_HEALTH_QUEUE_DEPTH_WARNING', 1000),
        // Fallback für das Datenalter, falls hub.sync.stale_after_seconds fehlt.
        'stale_after_seconds' => (int) env('HUB_HEALTH_STALE_AFTER_SECONDS', 86400),
    ],

    'directory' => [
        // Rollen, die im Verzeichnis ausgegeben werden. Leer bedeutet alle.
        'roles' => [],
        'default_per_page' => 100,
    ],

    'openapi' => [
        'title' => 'Immoware Hub API',
        'export_path' => 'docs/api/openapi.json',
    ],

    // Felder eines Kontakts, für die per PATCH ein Änderungsvorschlag angelegt werden darf.
    'contact_proposal_fields' => [
        'salutation', 'first_name', 'last_name', 'company_name', 'job_title', 'emails', 'phones', 'addresses',
    ],

    'case_statuses' => ['open', 'in_progress', 'waiting', 'closed'],
];
