<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Mail (Kern der Mail- und Vorgangsbearbeitung, docs/mail/01-architekturentscheidung.md).
 * Zugriff über config('hub.mail.*'). Alle Schreib- und Integrationsflags stehen standardmäßig auf false.
 * Ein Flag allein schaltet nichts frei: zusätzlich müssen Zugangsdaten hinterlegt, die Integration eingerichtet,
 * das Recht des Nutzers vorhanden und bei Außenwirkung eine Vier-Augen-Freigabe erteilt sein.
 */
return [

    // Domain der Mail-Oberfläche. Alle Routen aus routes/modules/mail.php werden mit Route::domain() gebunden.
    // Bestehende Routen (Admin, API, Login) bleiben ohne Domain-Bindung. In Tests mail.test setzen.
    'domain' => env('MAIL_APP_DOMAIN', 'mail.muellerhv.de'),

    // Produktionsdomain. Weicht die konfigurierte Domain ab oder ist APP_ENV=staging, sperrt MailBootGuard alle
    // Flags mit Außenwirkung (gmail_send, immoware_write, lexware_write).
    'production_domain' => 'mail.muellerhv.de',

    // Routen ohne Domainbindung, die auf dem Mail-Host erreichbar bleiben (Anmeldung, 2FA, Sicherheitsseiten, Health).
    // Alle anderen Hub-Routen (Admin-UI, /api) liefern auf dem Mail-Host 404 (MailServiceProvider::registerHostGuard).
    'host_allowed_route_prefixes' => ['login', 'security.', 'health', 'up'],

    // Zeitzone der Darstellung. Speicherung bleibt UTC (APP_TIMEZONE).
    'display_timezone' => env('MAIL_DISPLAY_TIMEZONE', 'Europe/Berlin'),

    'product_name' => 'Mail und Vorgänge',
    'operator' => 'Hausverwaltung Müller GmbH',

    // Feature-Flags (01, Abschnitt 8). Wirkung bei false siehe Dokumentation. Kein UI-Schalter, nur Umgebung.
    'flags' => [
        'import' => (bool) env('MAIL_IMPORT_ENABLED', false),
        'ai' => (bool) env('MAIL_AI_ENABLED', false),
        'gmail_drafts' => (bool) env('MAIL_GMAIL_DRAFTS_ENABLED', false),
        'gmail_send' => (bool) env('MAIL_GMAIL_SEND_ENABLED', false),
        'immoware_write' => (bool) env('MAIL_IMMOWARE_WRITE_ENABLED', false),
        'lexware_write' => (bool) env('MAIL_LEXWARE_WRITE_ENABLED', false),
        'paperless_write' => (bool) env('MAIL_PAPERLESS_WRITE_ENABLED', false),
    ],

    // Flags mit Außenwirkung, die in Staging und außerhalb der Produktionsdomain nie true sein dürfen.
    'staging_locked_flags' => [
        'gmail_send' => 'MAIL_GMAIL_SEND_ENABLED',
        'immoware_write' => 'MAIL_IMMOWARE_WRITE_ENABLED',
        'lexware_write' => 'MAIL_LEXWARE_WRITE_ENABLED',
        'paperless_write' => 'MAIL_PAPERLESS_WRITE_ENABLED',
    ],

    /*
     * Provider-Auswahl je Integration: live, fake oder null (nicht eingerichtet).
     * fake bindet die Testklassen aus App\Modules\<Modul>\Testing und ist in production verboten (MailBootGuard).
     * Die Bindung der Live-Implementierung erfolgt im jeweiligen Modul-Provider.
     */
    'providers' => [
        'gmail' => env('MAIL_GMAIL_PROVIDER'),
        'ai' => env('MAIL_AI_PROVIDER'),
        'lexware' => env('MAIL_LEXWARE_PROVIDER'),
        'drive' => env('MAIL_DRIVE_PROVIDER'),
        'paperless' => env('MAIL_PAPERLESS_PROVIDER'),
    ],

    /*
     * Queues, additiv zu high, default, sync, write, documents, low (01, Abschnitt 6).
     * Worker: --queue=mail-high,high,default,mail-sync,sync,write,documents,mail-ai,low oder zweiter Worker nur für mail-*.
     * compose.yaml, deploy/supervisor und deploy/systemd werden erst im Betriebsabschnitt angepasst.
     */
    'queues' => [
        // Die Worker (compose.yaml, deploy/supervisor, deploy/systemd) hören auf mail-high, mail-sync, mail-ai;
        // hub:doctor meldet abweichende Namen als fail (Jobs ohne Worker).
        'high' => env('MAIL_QUEUE_HIGH', 'mail-high'),
        'sync' => env('MAIL_QUEUE_SYNC', 'mail-sync'),
        'ai' => env('MAIL_QUEUE_AI', 'mail-ai'),
    ],

    // Job-Parameter nach Muster SyncJobRetries.
    'jobs' => [
        'tries' => (int) env('MAIL_JOB_TRIES', 5),
        'backoff' => [30, 120, 600, 1800],
        'timeout' => (int) env('MAIL_JOB_TIMEOUT', 300),
    ],

    // Rate Limit des Pub/Sub-Push-Endpunkts (Middleware-Gruppe mail.push), Anfragen je Minute.
    // Je Postfach (emailAddress der Nutzlast), nicht je IP: Pub/Sub sendet aus wenigen Google-Adressen. 429 wird protokolliert.
    'push_rate_limit_per_minute' => (int) env('MAIL_PUSH_RATE_LIMIT_PER_MINUTE', 600),
    // Zusätzliches globales Limit über alle Postfächer und Absender (Limiter mail-push-global, Schlüssel global):
    // begrenzt die Gesamtlast des Endpunkts, auch wenn viele verschiedene Postfachadressen oder IPs verwendet werden.
    'push_rate_limit_global_per_minute' => (int) env('MAIL_PUSH_RATE_LIMIT_GLOBAL_PER_MINUTE', 3000),

    /*
     * Globale Rechte des Mail-Moduls. Werden von MailServiceProvider::register() additiv in
     * hub.security.permission_catalog und hub.security.permissions gemerged; bestehende Rechte bleiben unverändert.
     * mail.mailbox.view ist ein dynamisches Gate mit Postfach-Argument (Gate::allows('mail.mailbox.view', $mailbox))
     * und wird über mail_mailbox_permissions entschieden (MailAccess::canViewMailbox).
     */
    'permission_catalog' => [
        'mail.inbox.view',
        'mail.case.assign',
        'mail.case.close_exception',
        'mail.task.manage',
        'mail.approve.standard',
        'mail.approve.bank',
        'mail.send',
        'mail.export',
        'mail.integrations.manage',
        'mail.bank_data.view',
        'mail.admin',
    ],

    // Obergrenze je Systemrolle (App\Core\Enums\Role). Owner erhält über * alles. api_client erhält nichts.
    'permissions' => [
        'administrator' => [
            'mail.inbox.view', 'mail.case.assign', 'mail.case.close_exception', 'mail.task.manage',
            'mail.approve.standard', 'mail.approve.bank', 'mail.send', 'mail.export', 'mail.integrations.manage',
            'mail.bank_data.view', 'mail.admin',
        ],
        'operator' => [
            'mail.inbox.view', 'mail.case.assign', 'mail.case.close_exception', 'mail.task.manage',
            'mail.approve.standard', 'mail.approve.bank', 'mail.send', 'mail.export', 'mail.bank_data.view',
        ],
        'developer' => ['mail.inbox.view', 'mail.export'],
        'read_only' => ['mail.inbox.view', 'mail.export'],
        'api_client' => [],
    ],

    /*
     * Team-Rollen (mail_team_members.team_role) als Rechtebündel. Ein Recht gilt nur, wenn Systemrolle (oben)
     * und Team-Rolle es gewähren (MailAccess::hasTeamPermission). Postfachinhalte zusätzlich nur mit Zeile in
     * mail_mailbox_permissions.
     */
    'team_roles' => [
        'admin' => [
            'mail.inbox.view', 'mail.case.assign', 'mail.case.close_exception', 'mail.task.manage',
            'mail.approve.standard', 'mail.approve.bank', 'mail.send', 'mail.export', 'mail.integrations.manage',
            'mail.bank_data.view', 'mail.admin',
        ],
        'lead' => [
            'mail.inbox.view', 'mail.case.assign', 'mail.case.close_exception', 'mail.task.manage',
            'mail.approve.standard', 'mail.send', 'mail.export', 'mail.bank_data.view',
        ],
        'agent' => ['mail.inbox.view', 'mail.task.manage', 'mail.send'],
        'approver' => ['mail.inbox.view', 'mail.approve.standard', 'mail.approve.bank', 'mail.bank_data.view'],
        'auditor' => ['mail.inbox.view', 'mail.export'],
    ],

    // Bezeichnungen der Team-Rollen für die Oberfläche.
    'team_role_labels' => [
        'admin' => 'Administrator',
        'lead' => 'Teamleitung',
        'agent' => 'Sachbearbeitung',
        'approver' => 'Freigabe',
        'auditor' => 'Prüfung (nur lesen)',
    ],

    // Vorgabe der Postfachrechte je Team-Rolle beim Anlegen (mail_mailbox_permissions), anpassbar je Nutzer.
    'mailbox_permission_defaults' => [
        'admin' => ['can_read' => true, 'can_draft' => true, 'can_send' => true, 'can_assign' => true, 'can_view_bank_data' => true],
        'lead' => ['can_read' => true, 'can_draft' => true, 'can_send' => true, 'can_assign' => true, 'can_view_bank_data' => true],
        'agent' => ['can_read' => true, 'can_draft' => true, 'can_send' => true, 'can_assign' => false, 'can_view_bank_data' => false],
        'approver' => ['can_read' => true, 'can_draft' => false, 'can_send' => false, 'can_assign' => false, 'can_view_bank_data' => true],
        'auditor' => ['can_read' => true, 'can_draft' => false, 'can_send' => false, 'can_assign' => false, 'can_view_bank_data' => false],
    ],

    // Bestätigungswort für gefährliche Aktionen in der Mail-Oberfläche (wie hub.admin.confirm_word).
    'confirm_word' => 'BESTÄTIGEN',

    // Gesellschaften, deren Absender nie vermischt werden (mail_mailboxes.legal_entity_code, mail_mailbox_aliases).
    'legal_entities' => [
        'HVM' => 'Hausverwaltung Müller GmbH',
        'MHAG' => 'Müller Holding AG',
    ],

    /*
     * Aufbewahrung (docs/mail/08-datenschutz-sicherheit.md Abschnitt 6). Angewendet durch mail:retention:apply
     * (Vorschau mit --dry-run, jeder Lauf auditiert). Fristen in Tagen sind Vorgaben je Organisation und werden durch
     * die Aufbewahrungseinstellung der Organisation (mail_org_settings, Schlüssel retention) überschrieben, sofern
     * dort gesetzt. Ein Vorgang mit legal_hold_at sperrt alle Löschungen an ihm und an seinen Nachrichten, Anhängen
     * und KI-Läufen. Der nächtliche Zeitplan läuft nur bei MAIL_RETENTION_ENABLED=true; der Befehl selbst ist immer
     * manuell aufrufbar. Fristen sind mit Steuerberater und Rechtsanwalt abzustimmen, nie eigenmächtig verkürzen.
     */
    'retention' => [
        'enabled' => (bool) env('MAIL_RETENTION_ENABLED', false),
        // Nachrichtentexte: body_text und body_html_sanitized werden entfernt, Metadaten bleiben (kein Hard Delete).
        'messages_days' => (int) env('MAIL_RETENTION_MESSAGES_DAYS', 3650),
        // Anhänge: Datei auf der Speicherdisk gelöscht, Zeile soft deleted, Metadaten bleiben.
        'attachments_days' => (int) env('MAIL_RETENTION_ATTACHMENTS_DAYS', 3650),
        // KI-Läufe (mail_ai_runs): Zeilen ohne Rohtext, werden nach Frist entfernt.
        'ai_runs_days' => (int) env('MAIL_RETENTION_AI_RUNS_DAYS', 365),
        // Push-Ereignisse (mail_push_events): nur Dedup-Zweck, 30 Tage.
        'push_events_days' => (int) env('MAIL_RETENTION_PUSH_EVENTS_DAYS', 30),
        // Abgeschlossene Vorgänge (closed oder archived): Soft Delete des Vorgangs samt Teilanliegen und Aufgaben.
        'closed_cases_days' => (int) env('MAIL_RETENTION_CLOSED_CASES_DAYS', 3650),
        // Zeilen je Durchlaufblock (chunkById), begrenzt Sperrdauer und Speicher.
        'chunk' => (int) env('MAIL_RETENTION_CHUNK', 500),
        // Uhrzeit des nächtlichen Laufs (display_timezone).
        'schedule_at' => env('MAIL_RETENTION_SCHEDULE_AT', '03:45'),
    ],

    /*
     * Auskunftsexport (DSGVO Art. 15, docs/mail/08 Abschnitt 7): mail:subject-access-export und Admin-Aktion. Läuft
     * asynchron auf der Queue low, nur mit Recht mail.export, Bankdaten maskiert, Ablage auf der Disk unterhalb von path.
     */
    'subject_access_export' => [
        'disk' => env('MAIL_EXPORT_DISK', 'local'),
        'path' => 'mail-exports',
        'queue' => 'low',
    ],

    /*
     * Notfallpfad. test_recipient ist die Empfängeradresse für Testalarme und die Probe von hub:doctor
     * (Bereitschaft, Eskalation). Leer bedeutet: kein Testversand konfiguriert, hub:doctor meldet warn.
     * Es handelt sich ausschließlich um eine interne Adresse, nie um eine Adresse von Mietern oder Eigentümern.
     */
    'emergency' => [
        'test_recipient' => env('MAIL_EMERGENCY_TEST_RECIPIENT'),
    ],
];
