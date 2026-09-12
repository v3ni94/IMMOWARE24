<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Security. Zugriff über config('hub.security.*').
 * Grundlage: docs/immoware/08-security.md, Abschnitte 3 bis 6.
 */
return [

    'login' => [
        // Fehlversuche bis zur Kontosperre und Dauer der Sperre in Minuten.
        'max_attempts' => (int) env('HUB_LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('HUB_LOGIN_LOCKOUT_MINUTES', 15),
        // Benachrichtigung bei Anmeldung von einer bisher unbekannten IP-Adresse.
        'notify_new_ip' => (bool) env('HUB_LOGIN_NOTIFY_NEW_IP', true),
        'notification_mailer' => env('HUB_SECURITY_MAILER', 'log'),
        'password_min_length' => 12,
    ],

    'totp' => [
        'issuer' => env('HUB_TOTP_ISSUER', 'Immoware Hub'),
        'period' => 30,
        'digits' => 6,
        'algorithm' => 'sha1',
        // Toleranz in Perioden vor und nach dem aktuellen Zeitfenster.
        'window' => 1,
        'recovery_codes' => 10,
        // Rollen, die ohne bestätigte 2FA auf Admin-Routen zugreifen dürfen. 08-security.md Abschnitt 3.1 verlangt
        // 2FA für alle Rollen; read_only besitzt exports.run und audit.view. Leer, Änderungsvermerk 12.09.2026.
        'exempt_roles' => [],
        // Sicherheitskritische Aktionen (Middleware 2fa.fresh, Äquivalent zu password.confirm) verlangen eine erneute
        // Authentifizierung (TOTP-Code oder Passwort), die höchstens so alt ist (Änderungsvermerk 12.09.2026: 15 Minuten).
        'fresh_minutes' => (int) env('HUB_TOTP_FRESH_MINUTES', 15),
    ],

    'sessions' => [
        // Tabelle des Session-Treibers database; nur damit lassen sich aktive Sitzungen anzeigen.
        'table' => 'sessions',
        // Absolute Obergrenze einer Sitzung ab Anmeldung, unabhängig von Aktivität (08-security.md: 8 Stunden).
        'absolute_minutes' => (int) env('HUB_SESSION_ABSOLUTE_MINUTES', 480),
    ],

    'api_keys' => [
        'key_prefix' => 'hub_live',
        'prefix_length' => 8,
        'secret_bytes' => 32,
        'max_lifetime_months' => 12,
        'rate_limit_per_minute' => (int) env('HUB_API_KEY_RATE_LIMIT_PER_MINUTE', 60),
        'scopes' => [
            'properties:read', 'units:read', 'contacts:read', 'contacts:write', 'contracts:read',
            'documents:read', 'documents:write', 'finance:read', 'cases:read', 'cases:write',
            'directory:read', 'sync:read', 'sync:trigger', 'conflicts:read', 'conflicts:resolve',
            'proposals:create', 'webhooks:manage', 'admin',
        ],
    ],

    'hashing' => [
        // Pepper für HMAC-SHA256 (ip_address_hash, iban_hash, base_url_hash). Fällt in Entwicklung auf APP_KEY zurück.
        'pepper' => env('HUB_HASH_PEPPER'),
    ],

    'audit' => [
        'anchor_location' => env('HUB_AUDIT_ANCHOR_LOCATION', 'local://audit-anchors'),
    ],

    /*
     * Katalog aller feingranularen Rechte. Jedes Recht wird als Gate registriert.
     */
    'permission_catalog' => [
        'connections.manage', 'sync.run', 'writes.request', 'writes.approve', 'api_keys.manage', 'users.manage',
        'audit.view', 'exports.run', 'imports.run', 'conflicts.resolve', 'webhooks.manage',
        // Datenherkunft: records.view = Detailseiten des Spiegels; payloads.view = archivierte Rohnutzlasten
        // (vCard, iCalendar, PROPFIND) mit personenbezogenen Daten, nur Owner, Administrator, Developer
        // (08-security.md Abschnitt 4: Read Only ohne Payload-Rohdaten, Änderungsvermerk 12.09.2026).
        'records.view', 'payloads.view',
    ],

    /*
     * Rechte je Rolle (App\Core\Enums\Role). Der Owner erhält alle Rechte des Katalogs (*).
     */
    'permissions' => [
        'owner' => ['*'],
        'administrator' => [
            'connections.manage', 'sync.run', 'api_keys.manage', 'users.manage', 'audit.view', 'exports.run',
            'writes.request', 'imports.run', 'conflicts.resolve', 'webhooks.manage', 'records.view', 'payloads.view',
        ],
        'developer' => ['sync.run', 'audit.view', 'exports.run', 'records.view', 'payloads.view'],
        'operator' => ['imports.run', 'conflicts.resolve', 'writes.request', 'exports.run', 'records.view'],
        'read_only' => ['audit.view', 'exports.run', 'records.view'],
        'api_client' => [],
    ],
];
