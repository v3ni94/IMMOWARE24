<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Lexware (Lexware Office Public API). Zugriff über config('hub.lexware.*').
 * Aussagen zur API stammen aus Snippets (docs/mail/research) und sind vor Implementierung am Original zu prüfen.
 * Nicht eingerichtet, wenn kein API-Key hinterlegt ist (mail_lexware_connections oder MAIL_LEXWARE_API_KEY).
 */
return [

    'base_url' => env('MAIL_LEXWARE_BASE_URL', 'https://api.lexoffice.io/v1'),
    'api_key' => env('MAIL_LEXWARE_API_KEY'),

    // Snippets nennen 2 Anfragen je Sekunde (aus Snippets, vor Produktivbetrieb am Original prüfen). Token-Bucket je Zugang, LexwareRateLimiter.
    'rate_limit_rps' => (float) env('MAIL_LEXWARE_RATE_LIMIT_RPS', 2),
    'timeout_seconds' => 15,

    // Kontaktänderung nur als GET, Versionsvergleich, PUT, GET (optimistic locking über version).
    'contact_update_requires_version' => true,

    // Endpunkte (aus Snippets, vor Produktivbetrieb am Original prüfen): contacts/{id} (GET, PUT), contacts (GET mit
    // Filtern email, name, number, customer, vendor; Pagination page, size, Antwort content, totalPages, last).
    'endpoints' => [
        'contact' => 'contacts/{id}',
        'contacts' => 'contacts',
    ],

    // Nie: Kunden anlegen (POST contacts), Belege oder Rechnungen ändern. Diese Pfade sind nicht implementiert.
    'forbidden_operations' => ['contacts.create', 'vouchers.*', 'invoices.*'],
];
