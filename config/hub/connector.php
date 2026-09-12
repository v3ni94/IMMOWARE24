<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Connector. Zugriff über config('hub.connector.*').
 * Alle Netzwerkwerte sind konservative Startwerte gemäß docs/immoware/06-rate-limits.md Abschnitt 3.
 */
return [

    // Kennung im User-Agent: ImmowareHub/<version>
    'version' => env('HUB_VERSION', '0.1.0'),

    'http' => [
        'connect_timeout_seconds' => (int) env('IMMOWARE_HTTP_CONNECT_TIMEOUT', 10),
        'timeout_seconds' => (int) env('IMMOWARE_HTTP_TIMEOUT', 60),
        'download_timeout_seconds' => (int) env('IMMOWARE_HTTP_DOWNLOAD_TIMEOUT', 300),
        // Methoden, die der Client unabhängig von jeder Konfiguration vor dem Senden abbricht.
        'blocked_methods' => ['DELETE', 'MOVE', 'COPY', 'PROPPATCH', 'LOCK', 'UNLOCK', 'MKCOL'],
    ],

    /*
     * Zulässige Ziel-Hosts für base_url einer Connection (08-security.md Abschnitt 9, Egress-Allowlist auf
     * Code-Ebene). Muster mit * als Platzhalter, kommagetrennt in HUB_CONNECTION_ALLOWED_HOSTS. Private,
     * lokale und reservierte Adressen werden unabhängig von der Liste abgelehnt.
     */
    'connections' => [
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('HUB_CONNECTION_ALLOWED_HOSTS', '*.immoware24.de'))))),
    ],

    'rate_limit' => [
        'default_rps' => (float) env('IMMOWARE_RATE_LIMIT_RPS', 2),
        'default_concurrency' => (int) env('IMMOWARE_MAX_CONCURRENCY_READ', 2),
        // Untergrenze bei Drosselung (06-rate-limits.md Abschnitt 4.1: mindestens 0,25 rps)
        'min_rps' => 0.25,
        // Dauer der automatischen Drosselung nach 429, Timeout, 5xx oder Latenzanstieg
        'throttle_seconds' => 600,
        // Latenz, ab der ein Request als Drosselungshinweis zählt
        'latency_threshold_ms' => (int) env('IMMOWARE_LATENCY_THRESHOLD_MS', 5000),
        // Faktor gegenüber dem gleitenden Mittel, ab dem die Latenz als steigend gilt
        'latency_spike_factor' => 3.0,
        // Ein reservierter Concurrency-Slot verfällt spätestens nach dieser Zeit (Schutz vor Leaks)
        'slot_ttl_seconds' => 300,
        'metrics_ttl_seconds' => 86400,
    ],

    'circuit_breaker' => [
        'failure_threshold' => 5,
        'failure_window_seconds' => 120,
        'open_seconds' => 600,
        // ab dem dritten Fehlzyklus
        'extended_open_seconds' => 3600,
        'extended_after_cycles' => 3,
    ],

    'remote_requests' => [
        'enabled' => (bool) env('HUB_REMOTE_REQUEST_LOG_ENABLED', true),
        'retention_days' => (int) env('HUB_REMOTE_REQUEST_RETENTION_DAYS', 90),
        'prune_chunk' => 1000,
    ],

    'probe' => [
        // Abstand der beiden PROPFIND Depth 1 zur ETag-Stabilitätsprüfung
        'etag_delay_seconds' => (int) env('HUB_PROBE_ETAG_DELAY_SECONDS', 60),
        // Maximal ausgewertete Einträge je Depth-1-Antwort
        'max_depth1_entries' => 500,
    ],

    /*
     * Capability-Schlüssel des Hubs. config_flag zeigt auf den Config-Pfad, der die Funktion
     * zusätzlich freigeben muss (null: kein Freigabe-Flag, Funktion bleibt gesperrt).
     * hard_locked Fähigkeiten können nie true werden, unabhängig von Status und Konfiguration.
     */
    'capabilities' => [
        'contacts.read' => ['config_flag' => 'hub.core.read.enabled', 'hard_locked' => false, 'connector' => 'carddav'],
        'contacts.write' => ['config_flag' => 'hub.core.write.carddav_enabled', 'hard_locked' => true, 'connector' => 'carddav'],
        'calendar.read' => ['config_flag' => 'hub.core.read.enabled', 'hard_locked' => false, 'connector' => 'caldav'],
        'calendar.write' => ['config_flag' => 'hub.core.write.caldav_enabled', 'hard_locked' => true, 'connector' => 'caldav'],
        'documents.read' => ['config_flag' => 'hub.core.read.enabled', 'hard_locked' => false, 'connector' => 'webdav'],
        // create-only PUT in den Posteingang, beide Flags müssen true sein
        'documents.write' => ['config_flag' => ['hub.core.write.enabled', 'hub.core.write.webdav_create_enabled'], 'hard_locked' => false, 'connector' => 'webdav'],
        'documents.delete' => ['config_flag' => 'hub.core.write.webdav_delete_enabled', 'hard_locked' => true, 'connector' => 'webdav'],
        'documents.move' => ['config_flag' => 'hub.core.write.webdav_move_enabled', 'hard_locked' => true, 'connector' => 'webdav'],
        'properties.read' => ['config_flag' => 'hub.core.read.enabled', 'hard_locked' => false, 'connector' => 'file_import'],
        'units.read' => ['config_flag' => 'hub.core.read.enabled', 'hard_locked' => false, 'connector' => 'file_import'],
        'contracts.read' => ['config_flag' => 'hub.core.read.enabled', 'hard_locked' => false, 'connector' => 'file_import'],
        'finance.read' => ['config_flag' => 'hub.core.read.enabled', 'hard_locked' => false, 'connector' => 'file_import'],
        'cases.read' => ['config_flag' => 'hub.core.read.enabled', 'hard_locked' => false, 'connector' => 'rest_api_slot'],
        'cases.write' => ['config_flag' => null, 'hard_locked' => false, 'connector' => 'rest_api_slot'],
    ],
];
