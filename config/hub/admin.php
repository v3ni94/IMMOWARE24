<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Admin. Zugriff über config('hub.admin.*').
 *
 * Die Navigation ist die verbindliche Liste der Admin-Bereiche. Jeder Eintrag verweist auf einen
 * Routennamen (admin.*). Existiert die Route noch nicht, zeigt das Layout den Bereich als "in Aufbau"
 * an, ohne zu verlinken. Das Recht (permission) stammt aus config/hub/security.php, permission_catalog;
 * null bedeutet: für alle angemeldeten Rollen außer api_client sichtbar.
 */
return [

    'product_name' => 'Immoware Hub',

    'operator' => 'Hausverwaltung Müller GmbH',

    // Zeilen je Seite in Admin-Tabellen (Pagination).
    'per_page' => (int) env('HUB_ADMIN_PER_PAGE', 50),

    // Bestätigungswort für gefährliche Aktionen (confirm-form).
    'confirm_word' => 'BESTÄTIGEN',

    'navigation' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => 'admin.dashboard', 'permission' => null],
        ['key' => 'connections', 'label' => 'Immoware-Verbindung', 'route' => 'admin.connections.index', 'permission' => 'connections.manage'],
        ['key' => 'capabilities', 'label' => 'Capabilities', 'route' => 'admin.capabilities.index', 'permission' => null],
        ['key' => 'sync', 'label' => 'Synchronisation', 'route' => 'admin.sync.index', 'permission' => null],
        ['key' => 'mapping', 'label' => 'Mapping', 'route' => 'admin.mapping.index', 'permission' => null],
        ['key' => 'conflicts', 'label' => 'Konflikte', 'route' => 'admin.conflicts.index', 'permission' => null],
        ['key' => 'dlq', 'label' => 'Fehlerqueue', 'route' => 'admin.dlq.index', 'permission' => null],
        ['key' => 'proposals', 'label' => 'Änderungsvorschläge', 'route' => 'admin.proposals.index', 'permission' => null],
        ['key' => 'imports', 'label' => 'Importe', 'route' => 'admin.imports.index', 'permission' => null],
        ['key' => 'webhooks', 'label' => 'Webhooks', 'route' => 'admin.webhooks.index', 'permission' => 'webhooks.manage'],
        ['key' => 'api', 'label' => 'API', 'route' => 'admin.api.index', 'permission' => 'api_keys.manage'],
        ['key' => 'users', 'label' => 'Benutzer', 'route' => 'admin.users.index', 'permission' => 'users.manage'],
        ['key' => 'roles', 'label' => 'Rollen', 'route' => 'admin.roles.index', 'permission' => 'users.manage'],
        ['key' => 'audit', 'label' => 'Auditlog', 'route' => 'admin.audit.index', 'permission' => 'audit.view'],
        ['key' => 'discovery', 'label' => 'Discovery-Konsole', 'route' => 'admin.discovery.index', 'permission' => null, 'roles' => ['developer', 'administrator', 'owner']],
        ['key' => 'export', 'label' => 'Export', 'route' => 'admin.export.index', 'permission' => 'exports.run'],
        ['key' => 'system', 'label' => 'System', 'route' => 'admin.system.index', 'permission' => 'connections.manage'],
    ],

    'dashboard' => [
        // Anzahl der zuletzt fehlgeschlagenen Läufe, die auf dem Dashboard erscheinen.
        'recent_failures' => 5,
    ],
];
