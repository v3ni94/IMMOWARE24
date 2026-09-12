<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Cases (Vorgänge, Teilanliegen, Aufgaben). Zugriff über config('hub.cases.*').
 * Gelesen ist nicht bearbeitet, beantwortet ist nicht erledigt, ein erfolgreicher HTTP-Aufruf ist kein
 * verifiziertes Geschäftsergebnis. Jeder offene Vorgang hat Verantwortlichen, nächsten Schritt und Fälligkeit.
 */
return [

    // Format der Vorgangsnummer: V-JJJJ-NNNNNN, fortlaufend je Jahr.
    'number_prefix' => 'V',

    // Vorgangstypen (mail_cases.case_type, mail_case_items.item_type).
    'case_types' => [
        'schaden_notfall' => 'Schaden Notfall',
        'schaden' => 'Schaden',
        'adressaenderung' => 'Adressänderung',
        'bankdaten' => 'Bankdatenänderung',
        'beschwerde' => 'Beschwerde',
        'anfrage_allgemein' => 'Allgemeine Anfrage',
        'rechnung' => 'Rechnung',
        'kuendigung' => 'Kündigung',
        'sonstiges' => 'Sonstiges',
    ],

    // Zuordnung jedes Vorgangstyps zu einer Abschlussgruppe (close_conditions).
    'case_type_groups' => [
        'schaden_notfall' => 'damage',
        'schaden' => 'damage',
        'adressaenderung' => 'master_data_change',
        'bankdaten' => 'bank_change',
        'beschwerde' => 'other',
        'anfrage_allgemein' => 'information',
        'rechnung' => 'invoice_query',
        'kuendigung' => 'master_data_change',
        'sonstiges' => 'other',
        // Gruppenschlüssel dürfen auch direkt als Typ verwendet werden.
        'information' => 'information',
        'damage' => 'damage',
        'master_data_change' => 'master_data_change',
        'bank_change' => 'bank_change',
        'invoice_query' => 'invoice_query',
        'other' => 'other',
    ],

    /*
     * Abschlussbedingungen je Gruppe (CloseConditionChecker). Ein Teilanliegen ist nur erledigt, wenn
     * Pflichtaufgaben erledigt (done_manual_confirmed oder done_verified), Aktionen verifiziert, keine offenen
     * Teilfehler bestehen und die Kommunikation abgeschlossen oder begründet entbehrlich ist.
     * requires_verified_business_result: status_business muss verified sein; sonst genügt, dass keine Aktion
     * begonnen wurde (reine Auskunft ohne Schreibaktion ist abschließbar).
     */
    'close_conditions' => [
        'information' => ['required_task_types' => [], 'requires_verified_business_result' => false, 'requires_communication' => true],
        'damage' => ['required_task_types' => ['contractor_order'], 'requires_verified_business_result' => false, 'requires_communication' => true],
        'master_data_change' => ['required_task_types' => ['manual_change_immoware'], 'requires_verified_business_result' => true, 'requires_communication' => true],
        'bank_change' => ['required_task_types' => ['manual_change_immoware', 'manual_change_lexware'], 'requires_verified_business_result' => true, 'requires_communication' => true],
        'invoice_query' => ['required_task_types' => ['internal_review'], 'requires_verified_business_result' => false, 'requires_communication' => true],
        'other' => ['required_task_types' => [], 'requires_verified_business_result' => false, 'requires_communication' => true],
    ],

    // Vorgangstypen, deren Alt/Neu-Werte verschlüsselt und maskiert geführt werden und die bei offener Zuordnung
    // (assignment_open) gesperrt sind.
    'sensitive_case_types' => ['bankdaten', 'bank_change', 'adressaenderung', 'master_data_change', 'kuendigung'],

    // Aufgabentypen (mail_tasks.task_type).
    'task_types' => [
        'manual_change_immoware' => 'Manuelle Änderung in Immoware24',
        'manual_change_lexware' => 'Manuelle Änderung in Lexware Office',
        'contractor_order' => 'Handwerker oder Notdienst beauftragen',
        'callback' => 'Rückruf',
        'site_visit' => 'Ortstermin',
        'document_request' => 'Unterlagen anfordern',
        'internal_review' => 'Interne Prüfung',
        'other' => 'Sonstiges',
    ],

    // Aufgabenstatus (App\Modules\Cases\Enums\TaskStatus), Abschluss nur done_manual_confirmed oder done_verified.
    'task_done_statuses' => ['done_manual_confirmed', 'done_verified'],

    /*
     * Regelbasierte Ableitung der Teilanliegen aus einer neuen Nachricht (Modul MailIntegration, CaseIntakeService).
     * Schlüsselwörter werden in Betreff und Text (Kleinschreibung) gesucht; jede getroffene Regel ergibt ein
     * Teilanliegen, ohne Treffer greift fallback. Notfallbegriffe stammen aus hub.sla.priority_rules.p0.
     */
    'intake' => [
        'rules' => [
            'schaden_notfall' => ['title' => 'Notfall (Schaden mit akuter Gefahr)', 'priority' => 'p0', 'keywords' => ['wasser tritt aus', 'wasser läuft', 'wasserrohrbruch', 'rohrbruch', 'brand', 'feuer', 'gasgeruch', 'gas riecht', 'akute gefahr', 'lebensgefahr', 'einsturz', 'explosion', 'rauch']],
            'schaden' => ['title' => 'Schadensmeldung', 'keywords' => ['wasserschaden', 'heizungsausfall', 'heizung ausgefallen', 'heizung fällt aus', 'stromausfall', 'aufzug', 'einbruch', 'kein warmwasser', 'schimmel', 'schaden', 'defekt', 'kaputt', 'tropft', 'undicht']],
            'adressaenderung' => ['title' => 'Adressänderung', 'keywords' => ['neue adresse', 'neue anschrift', 'adressänderung', 'adresse geändert', 'anschrift geändert', 'umgezogen', 'anschrift lautet', 'adresse lautet']],
            'bankdaten' => ['title' => 'Änderung der Bankverbindung', 'extract_iban' => true, 'keywords' => ['iban', 'bankverbindung', 'kontonummer', 'neue bank', 'neues konto', 'sepa', 'lastschrift']],
            'kuendigung' => ['title' => 'Kündigung', 'keywords' => ['kündigung', 'kündige', 'kuendigung']],
            'rechnung' => ['title' => 'Rückfrage zu Rechnung oder Abrechnung', 'keywords' => ['rechnung', 'abrechnung', 'nebenkosten', 'betriebskosten', 'hausgeld', 'mahnung']],
        ],
        'fallback' => ['item_type' => 'anfrage_allgemein', 'title' => 'Allgemeine Anfrage'],
    ],

    // Pflichtfelder eines offenen Vorgangs: Verantwortlicher, nächster Schritt, Fälligkeit.
    'open_requires' => ['assignee_user_id', 'next_step', 'due_at'],

    // Bearbeitungssperre: Laufzeit ohne Heartbeat in Sekunden.
    'lock_ttl_seconds' => 300,

    // Zuordnung: ab dieser Konfidenz erfolgt eine automatische Zuordnung (nur Kennung oder bestätigte Regel).
    'assignment' => [
        'auto_confidence' => 90,
        'sources' => ['identifier_email', 'assignment_rule', 'external_id_in_text', 'property_address', 'contract_party'],
        // Regex-Muster für Kundennummern und externe IDs im Text (Objektnummer, Vertragsnummer).
        'external_id_patterns' => ['/\b(?:OBJ|VE|MV|KD|WEG)-?\d{2,10}\b/iu', '/\b\d{5,12}\b/u'],
    ],

    // Kettenlänge der Stellvertretung (Vertreter des Vertreters).
    'substitution_max_depth' => 3,
];
