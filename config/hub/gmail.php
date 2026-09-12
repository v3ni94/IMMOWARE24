<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Gmail. Zugriff über config('hub.gmail.*'). Alle Aussagen zur Gmail-API, zu Google OAuth2
 * und zu Pub/Sub stammen aus Snippets (docs/mail/research/gmail-api.md) und sind vor Implementierung am Original zu
 * prüfen (docs/mail/07-gmail-berechtigungen.md). Zugangsdaten nur über Umgebung oder verschlüsselte Spalten, nie hier.
 * Ein Erfolg gegen Http::fake ist kein Live-Test; kein Baustein dieses Moduls wurde gegen die echte Gmail-API geprüft.
 */
return [

    // Basis-URLs (gemäß Gmail API Referenz, vor Produktivbetrieb am Original prüfen).
    'api' => [
        'base_url' => env('MAIL_GMAIL_API_BASE_URL', 'https://gmail.googleapis.com/gmail/v1'),
        'timeout_seconds' => (int) env('MAIL_GMAIL_API_TIMEOUT', 30),
        'connect_timeout_seconds' => (int) env('MAIL_GMAIL_API_CONNECT_TIMEOUT', 10),
        // Bei diesen Statuscodes gilt der Fehler als vorübergehend (Retry über die Sync-Backoff-Stufen).
        'retryable_statuses' => [429, 500, 502, 503, 504],
    ],

    // OAuth2-Client (Authorization Code mit PKCE und Refresh-Token je Postfach, keine Domain-wide Delegation).
    'oauth' => [
        'client_id' => env('MAIL_GMAIL_CLIENT_ID'),
        'client_secret' => env('MAIL_GMAIL_CLIENT_SECRET'),
        // Google-Endpunkte (aus Snippets, vor Produktivbetrieb am Original prüfen).
        'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'revoke_url' => 'https://oauth2.googleapis.com/revoke',
        // Getrennte Redirect-URIs je Umgebung; alle müssen in der Google-Cloud-Konsole eingetragen sein.
        'redirect_uris' => [
            'production' => env('MAIL_GMAIL_REDIRECT_URI_PRODUCTION', 'https://mail.muellerhv.de/mail/integrations/gmail/callback'),
            'staging' => env('MAIL_GMAIL_REDIRECT_URI_STAGING'),
            'local' => env('MAIL_GMAIL_REDIRECT_URI_LOCAL', 'http://mail.test/mail/integrations/gmail/callback'),
            'testing' => 'http://mail.test/mail/integrations/gmail/callback',
        ],
        'redirect_path' => '/mail/integrations/gmail/callback',
        // Gültigkeit des state-Parameters (Cache) in Sekunden.
        'state_ttl_seconds' => 600,
        // Puffer vor Ablauf des Access-Tokens, ab dem erneuert wird.
        'refresh_leeway_seconds' => 60,
        /*
         * Scope-Matrix (07): je Funktion der minimale Scope. Beim Verbinden werden nur die Scopes der aktivierten
         * Funktionen angefordert (functions). compose erlaubt technisch auch Versand, serverseitig über
         * MAIL_GMAIL_SEND_ENABLED begrenzt.
         */
        'scopes' => [
            'readonly' => 'https://www.googleapis.com/auth/gmail.readonly',
            'compose' => 'https://www.googleapis.com/auth/gmail.compose',
            'send' => 'https://www.googleapis.com/auth/gmail.send',
            'settings_basic' => 'https://www.googleapis.com/auth/gmail.settings.basic',
        ],
        'functions' => [
            'import' => ['readonly'],
            'drafts' => ['compose'],
            'send' => ['compose', 'send'],
            'aliases' => ['settings_basic'],
        ],
    ],

    // Pub/Sub-Push (users.watch). Nicht eingerichtet, wenn topic leer: Push-Endpunkt antwortet 404.
    'push' => [
        'topic' => env('MAIL_GMAIL_PUSH_TOPIC'),
        // aud des OIDC-Tokens: die in der Subscription konfigurierte Endpunkt-URL (https://mail.muellerhv.de/mail/gmail/push).
        'audience' => env('MAIL_GMAIL_PUSH_AUDIENCE'),
        'service_account_email' => env('MAIL_GMAIL_PUSH_SERVICE_ACCOUNT'),
        // Eigenes Token im Pfad als zweite Prüfung neben dem OIDC-JWT (leer: nur JWT).
        'path_token' => env('MAIL_GMAIL_PUSH_PATH_TOKEN'),
        // Google-Zertifikate (JWKS) und zulässige Issuer (aus Snippets, vor Produktivbetrieb am Original prüfen).
        'certs_url' => 'https://www.googleapis.com/oauth2/v3/certs',
        'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
        'certs_cache_seconds' => 3600,
        // Unbekannte kid: kurzer Negativ-Cache und Mindestabstand zwischen erzwungenen JWKS-Abrufen (Amplifikationsschutz).
        'unknown_kid_cache_seconds' => 60,
        'certs_reload_min_seconds' => 60,
        'clock_skew_seconds' => 60,
        'dedup_retention_days' => 30,
        // Watch läuft laut Snippets maximal 7 Tage; Erneuerung täglich, Alarm bei Restlaufzeit unter 24 Stunden.
        // Scheitert die Erneuerung endgültig, wird sie nach watch_retry_hours erneut eingeplant (nicht erst am Folgetag).
        'watch_renew_hours' => 24,
        'watch_alert_hours' => 24,
        'watch_retry_hours' => 6,
        'watch_label_ids' => ['INBOX', 'SENT', 'DRAFT'],
    ],

    'sync' => [
        'page_size' => 100,
        // Erstimport begrenzt: Anzahl und Zeitraum (Tage rückwirkend, 0 = kein Zeitfilter).
        'initial_import_max_messages' => (int) env('MAIL_GMAIL_INITIAL_IMPORT_MAX', 500),
        'initial_import_days' => (int) env('MAIL_GMAIL_INITIAL_IMPORT_DAYS', 90),
        'initial_import_label_ids' => ['INBOX', 'SENT'],
        // History-Typen und Labels des inkrementellen Abgleichs.
        'history_types' => ['messageAdded', 'messageDeleted', 'labelAdded', 'labelRemoved'],
        'history_label_ids' => ['INBOX', 'SENT', 'DRAFT'],
        // Nachrichten je Lauf (History wie Reconcile), danach plant sich der Job selbst erneut ein.
        'max_messages_per_run' => 200,
        // Nachricht aus messagesAdded bei Abruf noch nicht vorhanden (404, Eventual Consistency): History-ID nicht
        // fortschreiben, sondern den Lauf bis zu dieser Anzahl mit Verzögerung wiederholen.
        'history_missing_retries' => 3,
        'history_missing_retry_seconds' => 120,
        // Regelmäßiger Abgleich INBOX, SENT, DRAFT gegen die Datenbank (Lücken erkennen, nie löschen).
        'reconcile_label_ids' => ['INBOX', 'SENT', 'DRAFT'],
        'reconcile_page_size' => 100,
        'reconcile_interval_minutes' => 30,
        // Nachrichtenkörper werden per format=raw geladen und selbst geparst (MimeParser).
        'fetch_format' => 'raw',
        'attachment_max_bytes' => 26214400,
        'attachment_allowlist' => ['application/pdf', 'image/jpeg', 'image/png', 'text/plain', 'text/csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/msword', 'application/vnd.ms-excel'],
    ],

    /*
     * Quota-Zähler je Postfach (Einheiten je Minute, konservativ auf das neue Regime ab 01.05.2026 ausgelegt).
     * Kosten je Methode aus Snippets, vor Produktivbetrieb am Original prüfen.
     */
    'quota' => [
        'per_minute_per_mailbox' => (int) env('MAIL_GMAIL_QUOTA_PER_MINUTE', 5000),
        'costs' => [
            'messages.list' => 5,
            'messages.get' => 20,
            'threads.get' => 20,
            'history.list' => 2,
            'getProfile' => 1,
            'watch' => 100,
            'stop' => 50,
            'drafts.create' => 10,
            'drafts.update' => 15,
            'drafts.get' => 5,
            'drafts.send' => 100,
            'sendAs.list' => 5,
        ],
    ],

    'drafts' => [
        // Domain der selbst erzeugten Message-ID (<uuid@domain>).
        'message_id_domain' => env('MAIL_GMAIL_MESSAGE_ID_DOMAIN', 'mail.muellerhv.de'),
        'max_attachment_bytes' => 10485760,
    ],

    'send' => [
        // Abgleich nach drafts.send: Nachricht mit Label SENT und erwarteter Message-ID nachlesen.
        'reconcile_attempts' => 5,
        'reconcile_interval_seconds' => [60, 300, 900, 1800, 3600],
    ],
];
