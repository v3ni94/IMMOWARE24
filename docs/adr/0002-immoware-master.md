# ADR 0002: Immoware24 bleibt führendes System

Status: Angenommen
Datum: 11.09.2026
Entscheider: Geschäftsführung Hausverwaltung Müller GmbH, Projektleitung Immoware Hub

## Kontext

Die Hausverwaltung Müller GmbH führt WEG- und Mietverwaltung, Buchhaltung, DMS, Postausgang, Ticketsystem und Portal24 in Immoware24. Der Hub soll Daten für eigene Prozesse und spätere Konsumenten verfügbar machen. Zur Frage, wo die fachliche Wahrheit liegt, wurden drei Modelle erwogen:

1. Hub als Master mit Rückschreiben nach Immoware24 (bidirektional).
2. Immoware24 als Master, Hub als versionierter Spiegel mit einem eng begrenzten Schreibpfad.
3. Parallele Wahrheiten je Domäne (z. B. Kontakte im Hub, Buchhaltung in Immoware24).

Belegstand, der die Entscheidung bestimmt:

- Schreibzugriff über CardDAV und CalDAV ist nicht belegt (Status VERMUTET bzw. unklar). Ein Snippet "nur lesend" existiert, ist aber nicht eindeutig zuordenbar (VERMUTET).
- Schreibzugriff über WebDAV ist belegt: "Die Ordner Posteingang und Dokumente können überschrieben werden. Änderungen und Löschvorgänge wirken sich entsprechend auf das eingebundene System aus." (VERIFIZIERT für diesen Kernsatz, der im Snippet der offiziellen Anleitung zum DAV-Adapter, © 2023 Immoware24 GmbH, nahezu wörtlich gesehen wurde; der Volltext des PDF wurde nicht gelesen, Abgleich in Phase 0; die Einleitungsformulierung "Bei der WebDAV-Integration ist zu beachten" in anderen Dokumenten ist Paraphrase und gilt als DOKUMENTIERT).
- Eine REST-API, Webhooks oder API-Keys sind NICHT VERFÜGBAR.
- Der DMS-Papierkorb wird nach 7 Tagen automatisiert gelöscht (DOKUMENTIERT, AGB).
- Buchhaltung, Zahlungsverkehr, DATEV, Banking, E-Post, Portal24 sind als UI-Funktionen von Immoware24 belegt (DOKUMENTIERT). Eine Fremd-API zu diesen Bereichen ist NICHT VERFÜGBAR (Negativbefund, keine offizielle Aussage).

## Entscheidung

Option 2. Immoware24 ist Master für alle fachlichen Daten. Der Hub hält einen versionierten Spiegel und hält niemals die einzige Kopie einer fachlichen Wahrheit. Hub-eigene Daten sind ausschließlich Konfliktentscheidungen, logische Merges (merged_into_id), Zuordnungen (external_mappings mit created_by manual oder bootstrap), Importformate, Capabilities, Nutzer, API-Keys und Webhook-Endpunkte. Diese werden gesondert gesichert (hub_decision_backups).

Änderungswünsche an Immoware24-Daten, für die kein belegter Schreibweg existiert, werden als `proposed_change` in der Konfliktqueue erfasst und von einem Mitarbeiter manuell in Immoware24 umgesetzt. Der nächste Sync bestätigt die Umsetzung über den Hash.

## Begründung

- Bidirektionaler Sync setzt einen belegten, idempotenten Schreibweg voraus. Der existiert nur für WebDAV-Uploads. Ein Rückschreiben von Kontakten oder Terminen über DAV wäre eine Wette auf unbelegtes Serververhalten und würde bei Fehlern direkt im Livesystem wirken.
- Parallele Wahrheiten erzeugen Abstimmungsaufwand, den eine Hausverwaltung mit 869 Einheiten nicht tragen sollte, und sie brechen die Nachvollziehbarkeit gegenüber Eigentümern und Steuerberater.
- Ein Spiegel mit Rohdatenarchiv (external_payloads) ist jederzeit aus Immoware24 neu aufbaubar. Der Verlust des Hubs ist damit ein Betriebsproblem, kein Datenverlust.

## Konsequenzen

- Jede Hub-Ausgabe trägt data_age_seconds, stale_since und source_status, weil Stammdaten und Buchhaltung nur über manuelle Exporte ankommen.
- Der Hub darf keine fachlichen Felder eines Spiegeldatensatzes ändern. Annotationen sind eigene Spalten oder Tabellen und werden bei entfernter Änderung nicht überschrieben (Konflikttyp local_change_vs_remote).
- Merges von Kontakten sind ausschließlich logisch (merged_into_id, rückgängig machbar), nie physisch.
- Die Exit-Strategie liegt außerhalb des Hubs: monatlicher DATEV-Export, CSV-Auswertungen aller Objekte, Dokumentliste per WebDAV, weil eine Exportregelung bei Vertragsende laut Snippet in den AGB nicht belegt ist (VERMUTET, durch Rechtsanwalt zu prüfen).

## Nicht entschieden

Ob Immoware24 hochgeladene Dateien im Posteingang automatisch Objekten zuordnet, ist NICHT belegt. Der Hub liefert nur eine Dateinamenskonvention. Dieser Punkt wird in Phase 2 am eigenen Mandanten getestet.
