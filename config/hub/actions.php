<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Actions. Zugriff über config('hub.actions.*').
 * Allowlist der action_keys je Zielsystem aus docs/mail/03-capability-matrix.md. Alles außerhalb der Liste
 * wird als blocked_capability abgewiesen. Immoware24: Adress- und Bankdatenänderung sind nicht verfügbar und
 * laufen als manuelle Aufgabe mit Alt/Neu und "Manuell bestätigt".
 */
return [

    'allowlist' => [
        'immoware24' => [
            'immoware.posteingang.upload' => ['risk_class' => 'medium', 'flag' => 'immoware_write', 'verification' => 'propfind_etag'],
            'immoware.contact.read' => ['risk_class' => 'low', 'flag' => null, 'verification' => null],
        ],
        'lexware' => [
            'lexware.contact.read' => ['risk_class' => 'low', 'flag' => null, 'verification' => null],
            'lexware.contact.update_address' => ['risk_class' => 'high', 'flag' => 'lexware_write', 'verification' => 'lexware_version_compare'],
        ],
        'gmail' => [
            'gmail.draft.create' => ['risk_class' => 'low', 'flag' => 'gmail_drafts', 'verification' => 'reread_get'],
            'gmail.draft.send' => ['risk_class' => 'high', 'flag' => 'gmail_send', 'verification' => 'gmail_sent_label'],
        ],
        'drive' => [
            'drive.file.link' => ['risk_class' => 'low', 'flag' => null, 'verification' => 'reread_get'],
        ],
        'manual' => [
            'manual.change.immoware_address' => ['risk_class' => 'medium', 'flag' => null, 'verification' => 'manual_confirmation'],
            'manual.change.immoware_bank' => ['risk_class' => 'bank', 'flag' => null, 'verification' => 'manual_confirmation'],
            'manual.change.lexware_address' => ['risk_class' => 'medium', 'flag' => null, 'verification' => 'manual_confirmation'],
            'manual.other' => ['risk_class' => 'low', 'flag' => null, 'verification' => 'manual_confirmation'],
        ],
    ],

    /*
     * Serverseitige Allowlist der Aktionstypen der Action-Engine je Zielsystem (App\Modules\Actions\Services\ActionAllowlist).
     * Nur diese Typen sind planbar; die Prüfung liegt im Service, nicht im Controller. mode:
     *   manual_task   Schreibfähigkeit fehlt, Adapter erzeugt eine manuelle Aufgabe mit Alt/Neu und proposed_change
     *   api           Adapter schreibt über die Schnittstelle des Zielsystems (nur mit Flag, Freigabe, Verifikation)
     * risk_class ist die Mindestklasse; bank verlangt Identitätsprüfung und zwei verschiedene Freigebende.
     */
    'action_types' => [
        'immoware24' => [
            'address_change' => ['risk_class' => 'medium', 'mode' => 'manual_task', 'flag' => null, 'fields' => ['street', 'postal_code', 'city', 'country']],
            'bank_change' => ['risk_class' => 'bank', 'mode' => 'manual_task', 'flag' => null, 'fields' => ['iban', 'bic', 'account_holder']],
            'note' => ['risk_class' => 'low', 'mode' => 'manual_task', 'flag' => null, 'fields' => ['text']],
            'manual_task' => ['risk_class' => 'low', 'mode' => 'manual_task', 'flag' => null, 'fields' => ['title', 'instructions']],
        ],
        'lexware' => [
            'address_change' => ['risk_class' => 'high', 'mode' => 'api', 'flag' => 'lexware_write', 'fields' => ['street', 'zip', 'city', 'countryCode']],
            'note' => ['risk_class' => 'medium', 'mode' => 'api', 'flag' => 'lexware_write', 'fields' => ['note']],
            'manual_task' => ['risk_class' => 'low', 'mode' => 'manual_task', 'flag' => null, 'fields' => ['title', 'instructions']],
        ],
        'manual' => [
            'manual_task' => ['risk_class' => 'low', 'mode' => 'manual_task', 'flag' => null, 'fields' => ['title', 'instructions']],
            'address_change' => ['risk_class' => 'medium', 'mode' => 'manual_task', 'flag' => null, 'fields' => ['street', 'postal_code', 'city', 'country']],
            'bank_change' => ['risk_class' => 'bank', 'mode' => 'manual_task', 'flag' => null, 'fields' => ['iban', 'bic', 'account_holder']],
        ],
    ],

    'approvals' => [
        // Anzahl benötigter Freigaben je Risikoklasse. bank verlangt immer zwei verschiedene Personen.
        'required' => ['low' => 0, 'medium' => 1, 'high' => 1, 'bank' => 2],
        // Gültigkeit einer Freigabe. Abgelaufene Freigaben sperren die Ausführung (Vorbedingung).
        'ttl_hours' => (int) env('MAIL_ACTION_APPROVAL_TTL_HOURS', 72),
    ],

    'jobs' => [
        'queue' => env('MAIL_ACTION_QUEUE', 'mail-high'),
        'tries' => (int) env('MAIL_ACTION_JOB_TRIES', 3),
        'backoff' => [30, 120, 600],
        'timeout' => (int) env('MAIL_ACTION_JOB_TIMEOUT', 120),
        'lock_seconds' => (int) env('MAIL_ACTION_LOCK_SECONDS', 300),
        // Wartezeit vor dem Nachlesen nach result_unclear
        'verify_delay_seconds' => (int) env('MAIL_ACTION_VERIFY_DELAY', 60),
    ],

    // Datenalter des Spiegels, ab dem readCurrent für Immoware24 als veraltet gilt (Warnung, keine Sperre).
    'immoware_max_data_age_seconds' => (int) env('MAIL_ACTION_IMMOWARE_MAX_AGE', 86400),

    /*
     * Deep-Link-Muster in Immoware24 für manuelle Aufgaben, z. B. https://<mandant>.immoware24.de/kontakte/{external_id}.
     * Kein Standardwert: Ohne konfiguriertes Muster enthält die Aufgabe keinen Link (nichts erfinden).
     */
    'immoware_deep_link_pattern' => env('MAIL_ACTION_IMMOWARE_DEEP_LINK'),

    // Antwortauszüge werden maskiert und auf diese Länge gekürzt.
    'response_excerpt_max' => 4000,

    // Outbox-Events (mail_outbox.event).
    'outbox_events' => ['case.created', 'case.assigned', 'case.escalated', 'task.due', 'draft.approved', 'plan.approved', 'plan.scheduled', 'execution.started', 'execution.failed', 'execution.result_unclear', 'execution.verified', 'execution.manual_task'],
];
