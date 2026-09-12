<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls MailUi (Oberfläche unter mail.muellerhv.de). Zugriff über config('hub.mailui.*').
 * Navigation analog hub.admin.navigation: fehlende Routen erscheinen als "in Aufbau".
 */
return [

    'per_page' => (int) env('MAIL_UI_PER_PAGE', 50),

    'navigation' => [
        ['key' => 'dashboard', 'label' => 'Übersicht', 'route' => 'mail.dashboard', 'permission' => null],
        ['key' => 'inbox', 'label' => 'Posteingang (Team)', 'route' => 'mail.inbox.index', 'permission' => 'mail.inbox.view'],
        ['key' => 'work', 'label' => 'Meine Arbeit', 'route' => 'mail.work.index', 'permission' => 'mail.inbox.view'],
        ['key' => 'approvals', 'label' => 'Freigabecenter', 'route' => 'mail.approvals.index', 'permission' => 'mail.approve.standard'],
        ['key' => 'escalations', 'label' => 'Eskalationen', 'route' => 'mail.escalations.index', 'permission' => 'mail.case.assign'],
        ['key' => 'reports', 'label' => 'Berichte', 'route' => 'mail.reports.index', 'permission' => 'mail.export'],
        ['key' => 'integrations', 'label' => 'Integrationen', 'route' => 'mail.integrations.index', 'permission' => 'mail.integrations.manage'],
        ['key' => 'admin', 'label' => 'Administration', 'route' => 'mail.admin.teams.index', 'permission' => 'mail.admin'],
        ['key' => 'setup', 'label' => 'Einrichtungsassistent', 'route' => 'mail.admin.setup.show', 'permission' => 'mail.admin'],
    ],

    // Unterbereiche der Administration (Reiter auf den Admin-Seiten).
    'admin_sections' => [
        ['key' => 'teams', 'label' => 'Teams und Rollen', 'route' => 'mail.admin.teams.index'],
        ['key' => 'mailboxes', 'label' => 'Postfächer und Aliasse', 'route' => 'mail.admin.mailboxes.index'],
        ['key' => 'responsibilities', 'label' => 'Objektzuständigkeiten', 'route' => 'mail.admin.responsibilities.index'],
        ['key' => 'calendars', 'label' => 'Arbeitszeiten und Feiertage', 'route' => 'mail.admin.calendars.index'],
        ['key' => 'sla', 'label' => 'SLA-Regeln', 'route' => 'mail.admin.sla.index'],
        ['key' => 'settings', 'label' => 'Eskalation, Bereitschaft, KI, Aufbewahrung', 'route' => 'mail.admin.settings.index'],
        ['key' => 'setup', 'label' => 'Einrichtungsassistent', 'route' => 'mail.admin.setup.show'],
    ],

    // Kategorien der Vorgänge (mail_cases.case_type). Schlüssel maximal 32 Zeichen.
    'case_types' => [
        'schaden' => 'Schaden und Reparatur',
        'notfall' => 'Notfall',
        'bankdaten' => 'Bankdaten und SEPA',
        'adresse' => 'Adress- und Kontaktänderung',
        'abrechnung' => 'Abrechnung und Zahlung',
        'vertrag' => 'Mietvertrag und Kündigung',
        'weg' => 'WEG und Versammlung',
        'beschwerde' => 'Beschwerde',
        'behoerde' => 'Behörde und Gericht',
        'rechnung' => 'Rechnung (Lexware)',
        'sonstiges' => 'Sonstiges',
    ],

    /*
     * Routen der Fachmodule für Verbinden und Widerrufen je Integration. Fehlt die Route zur Laufzeit,
     * zeigt die Integrationsseite den Button deaktiviert (kein Fallback auf eigene Logik).
     */
    'integration_routes' => [
        'gmail' => ['connect' => 'mail.integrations.gmail.connect', 'revoke' => 'mail.integrations.gmail.revoke'],
        'lexware' => ['connect' => 'mail.integrations.lexware.connect', 'revoke' => 'mail.integrations.lexware.revoke'],
        'drive' => ['connect' => 'mail.integrations.drive.connect', 'revoke' => 'mail.integrations.drive.revoke'],
        'ai' => ['connect' => 'mail.integrations.ai.connect', 'revoke' => 'mail.integrations.ai.revoke'],
    ],

    // Tastaturkürzel der Dreispalten-Ansicht (Hinweise im Layout).
    'shortcuts' => [
        'j' => 'Nächster Vorgang',
        'k' => 'Voriger Vorgang',
        'a' => 'Zuweisen',
        'e' => 'Öffnen (Enter)',
        'r' => 'Antworten (Entwurf)',
        '?' => 'Hilfe zu Kürzeln',
    ],
];
