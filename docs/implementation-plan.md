# Immoware Hub, Implementierungsplan

Stand: 11.09.2026, Statusspalte ergänzt am 12.09.2026. Auftraggeber: Hausverwaltung Müller GmbH. Zielsystem: Laravel 13, PHP 8.4, MariaDB 10.11+, Redis 7, Scheduler (Änderungsvermerk 12.09.2026: Laravel 13 statt 12, kein Horizon, Queue-Worker über supervisord oder systemd, siehe docs/operations/01-deployment.md).

Grundlage sind die Architekturentscheidung, das Datenmodell und die Schnittstellenrecherche in `docs/immoware/`. Der Plan gliedert die vier Grobphasen der Architekturentscheidung (Phase 0 Voraussetzungen, Phase 1 lesend, Phase 2 Schreiben, Phase 3 Buchhaltung, Phase 4 Konsumenten) in 15 Arbeitsphasen 0 bis 14 mit Definition of Done.

Aufwandsangaben sind Schätzungen in Personenwochen (PW) für ein Team aus einer Entwicklerin oder einem Entwickler in Vollzeit plus fachlicher Begleitung durch die Hausverwaltung. Sie sind zu verifizieren, sobald Phase 0 abgeschlossen ist.

## Belegregeln für diesen Plan

- Jede Aussage zu Immoware24 trägt ihren Status: VERIFIZIERT, DOKUMENTIERT, VERMUTET, NICHT VERFÜGBAR.
- Kein Arbeitspaket darf auf einer Aussage mit Status VERMUTET oder NICHT VERFÜGBAR aufbauen, bevor der zugehörige Testpunkt am eigenen Mandanten abgeschlossen und als Testprotokoll unter `docs/immoware/` abgelegt ist.
- Offene Informationsbedarfe sind gekennzeichnet mit WAITING_FOR_VENDOR_ACCESS (Antwort oder Freischaltung durch Immoware24 nötig) oder "zu verifizieren am eigenen Mandanten" (eigener Test mit autorisiertem Zugang).
- Jede Phase endet mit einem Eintrag im Repository (Testprotokoll, Bericht oder ADR). Ohne diesen Eintrag ist die Phase nicht abgeschlossen.

## Übersicht

| Phase | Titel | Grobphase | Aufwand | Blockierende Abhängigkeit | Status 12.09.2026 |
|---|---|---|---|---|---|
| 0 | Voraussetzungen, Zugang, Probe | 0 | 2 bis 3 Wochen Kalender, 1 PW | DAV-Modul gebucht | offen (WAITING_FOR_VENDOR_ACCESS, Probe am Mandanten nicht begonnen) |
| 1 | Projektgerüst, Core, Auth, Audit | 1 | 1,5 PW | keine | Code vorhanden, am Mandanten ungetestet |
| 2 | Capability Registry, Probe-Kommando, Connection-Verwaltung | 1 | 1 PW | Phase 0 Probe-Ergebnisse, Phase 1 | Code vorhanden, am Mandanten ungetestet |
| 3 | WebDAV-Dokumentenspiegel (lesend) | 1 | 1,5 PW | Phase 2 | Code vorhanden, am Mandanten ungetestet |
| 4 | CardDAV-Kontaktspiegel (lesend) | 1 | 1 PW | Phase 2 | Code vorhanden, am Mandanten ungetestet |
| 5 | CSV-Import Stammdaten mit Bootstrap | 1 | 1,5 PW | Phase 0 CSV-Dateien, Phase 1 | Code vorhanden, am Mandanten ungetestet (Spaltenformate offen) |
| 6 | Konfliktqueue, proposed_change, Datenalter, Export-Erinnerungen | 1 | 1 PW | Phasen 3 bis 5 | Code vorhanden, am Mandanten ungetestet |
| 7 | Resilienz, Degraded-Mode, DLQ, Backups, Betrieb | 1 | 1 PW | Phase 6 | Code vorhanden, am Mandanten ungetestet (Betriebsunterlagen unter docs/operations/) |
| 8 | Schreibpfad Posteingang, Freigabeprozess | 2 | 1,5 PW | Phase 7, Supportbestätigung, GF-Freigabe | Code vorhanden, am Mandanten ungetestet, Flags deaktiviert |
| 9 | Pilotbetrieb Schreibpfad und Abnahme | 2 | 0,5 PW plus 4 Wochen Pilot | Phase 8 | offen |
| 10 | DATEV-Buchungsexport und OP-Listen (lesend) | 3 | 1 PW | Phase 5, DATEV-Datei aus Phase 0 | Code vorhanden, am Mandanten ungetestet (DATEV-Datei offen) |
| 11 | CAMT.053 und optional HeiWaKo (lesend) | 3 | 1 PW | Phase 10 | Code vorhanden, am Mandanten ungetestet (CAMT.053, HeiWaKo nicht gebaut) |
| 12 | CalDAV-Terminspiegel (lesend) | 3 | 0,5 PW | Phase 4 | Code vorhanden, am Mandanten ungetestet |
| 13 | Ausgehende Webhooks für benannte Konsumenten (n8n) | 4 | 1 PW | Phase 9, benannter Konsument | Code vorhanden, am Mandanten ungetestet, standardmäßig deaktiviert |
| 14 | Lesende API mit Scopes, Regelbetrieb, Review | 4 | 1 PW | Phase 13 | Code vorhanden, am Mandanten ungetestet |

Statusspalte (Änderungsvermerk 12.09.2026): "Code vorhanden" bedeutet, dass der Anwendungscode der Phase im Repository liegt und automatisiert gegen simulierte DAV-Server (`Http::fake()`, Mock-Server unter `tests/mock-immoware/`) getestet ist. "am Mandanten ungetestet" bedeutet, dass die Definition of Done noch nicht erfüllt ist, weil das Testprotokoll am eigenen Immoware24-Mandanten fehlt. Nicht im Plan enthaltene, aber gebaute Querschnittsbausteine: Admin-Oberfläche (Modul Admin, 17 Bereiche), MCP-Tool-Schicht (Modul Mcp), Mock-Immoware-Server und Contract-Tests, Betriebsunterlagen (Dockerfile, compose.yaml, deploy/, docs/operations/).

Kritischer Pfad: 0, 1, 2, 3, 6, 7, 8, 9. Phasen 4, 5, 10, 11, 12 können parallel geplant werden, sobald ihre Voraussetzungen vorliegen.

---

## Phase 0: Voraussetzungen, Zugang, Probe

Ziel: Alle Blocker beseitigen und die unbelegten Annahmen der Architektur am eigenen Mandanten messen. Vor Abschluss dieser Phase wird kein Anwendungscode geschrieben, mit Ausnahme des Probe-Skripts.

Arbeitspakete:

- AP 0.1 DAV-Modul buchen. Kontakt mit Immoware24-Support oder Vertrieb, Kosten einholen, Freigabe der Geschäftsführung dokumentieren. Beleg: "Um die Möglichkeiten der Datei-, Kalender- und Kontaktfreigabe nutzen zu können, muss man den Support oder den Ansprechpartner im Vertrieb kontaktieren, um die Funktionalitäten zu buchen." (VERIFIZIERT, Snippet des Artikels 360010876038). Kostenhöhe: WAITING_FOR_VENDOR_ACCESS.
- AP 0.2 Schriftliche Anfrage an den Immoware24-Support: Ist ein automatisierter WebDAV-Zugriff (Lesen, Upload in den Posteingang) durch eine Serveranwendung der Hausverwaltung mit eigenem Nutzer zulässig? Gibt es eine API, Webhooks oder einen Exportmechanismus, der öffentlich nicht dokumentiert ist? Gibt es Rate Limits oder Nutzungsgrenzen? Status: WAITING_FOR_VENDOR_ACCESS.
- AP 0.3 Technische Nutzer anlegen: ein Lesenutzer, ein Schreibnutzer, jeweils mit der kleinsten Rolle, die DAV-Freigaben trägt. Welche Rolle das ist, ist nicht belegt (Nutzerrollen SYS: Administrator, SYS: Standard, SYS: nur Lesezugriff, SYS: nur Lesezugriff Stammdaten sind DOKUMENTIERT). Zu verifizieren am eigenen Mandanten.
- AP 0.4 Freigaben durch admin im Konfigurationsportal (VERIFIZIERT: https://config.dav.immoware24.de/login, Menüpunkt Verwaltung, Nutzer freischalten, Freigabetypen Dateifreigabe, Kalender, Kontakte, Freigaben nicht editierbar). Prüfen, ob eine Dateifreigabe auf den Ordner Posteingang beschränkbar ist (VERMUTET). Freigabe-Passwörter setzen und im Secret-Store ablegen.
- AP 0.5 Manuelle Probe mit Standard-Client (Windows Explorer oder macOS Finder, DAVx5 oder Thunderbird): Ordnerumfang per WebDAV sichten (mindestens Posteingang und Dokumente sind VERIFIZIERT), Kontaktfreigaben und deren Aufteilung nach Kontakttypen (VERMUTET) sichten.
- AP 0.6 Technische Probe mit Skript (curl oder kleines PHP-Skript, noch kein Laravel): OPTIONS, PROPFIND Depth 0 und 1, Auth-Challenge (Basic oder Digest, VERMUTET), DAV-Header, supported-report-set, sync-token, calendarserver-getctag, ETag-Stabilität über zwei Abfragen im Abstand von 60 Sekunden, If-None-Match-Verhalten mit einer Testdatei im Posteingang (zweites PUT muss 412 liefern). Die Testdatei wird anschließend von einem berechtigten Mitarbeiter im DMS gelöscht, nicht per DAV.
- AP 0.7 Je eine reale Exportdatei sichten und im Repository ablegen (pseudonymisiert oder mit Zugriffsschutz): Auswertung Mieter- und VE-Stammdaten, Kontakte, Liste offener Posten, DATEV-Buchungsstapel. Spalten, Trennzeichen, Zeichensatz, Schlüsselspalten dokumentieren. Prüfen, ob die OP-Liste über den Export-Button verfügbar ist (zu verifizieren am eigenen Mandanten).
- AP 0.8 Quellen sichern: AGB-Wortlaut (automatisierter Zugriff, Papierkorb 7 Tage, Regelung bei Vertragsende), Anleitung zum DAV-Adapter (PDF), relevante Support-Artikel als Screenshot oder Textkopie aus dem eingeloggten Zugang. Snippet-Paraphrasen in `docs/immoware/` durch Originalzitate ersetzen oder als weiterhin vorläufig markieren.
- AP 0.9 Rechtliche Prüfung: AGB und AV-Vertrag mit Immoware24 durch Rechtsanwalt, Löschfristen personenbezogener Daten mit Rechtsanwalt und Steuerberater, Aufbewahrung buchhaltungsrelevanter Dateien (10 Jahre, mit Steuerberater abstimmen).

Definition of Done:

- DAV-Modul gebucht, Kosten freigegeben, Freigabeprotokoll der Geschäftsführung im Repository.
- Antwort des Immoware24-Supports liegt vor oder ist als offen mit Datum der Anfrage dokumentiert; ohne positive Antwort darf Phase 8 nicht beginnen.
- Testprotokoll `docs/immoware/10-test-report.md` fortgeschrieben mit Ergebnissen zu Auth-Schema, ETag-Stabilität, sync-token, CTag, If-None-Match, Ordnerumfang, Rollenzuordnung, Beschränkbarkeit der Freigabe.
- Je Exporttyp eine Datei gesichtet, Header dokumentiert, Schlüsselspalten vorgeschlagen.
- Rückfallentscheidung getroffen: DAV first bleibt, Intervalle verlängert, oder Hub wird reiner Datei-Import-Spiegel (siehe ADR 0003).

Offene Informationsbedarfe: alle oben genannten Punkte mit WAITING_FOR_VENDOR_ACCESS oder "zu verifizieren am eigenen Mandanten".

---

## Phase 1: Projektgerüst, Core, Auth, Audit

Ziel: Lauffähiges Laravel-Projekt mit Modulstruktur, Authentifizierung mit Pflicht-2FA, Rollen und append-only Auditlog.

Arbeitspakete:

- AP 1.1 Laravel 12 aufsetzen, PHP 8.4, MariaDB 10.11+, Redis 7, Horizon, Scheduler. Modulstruktur `app/Modules/{Core,Connectors,Capability,Probe,Sync,Domain,Writes,Conflicts,Resilience,Outbound}` (ADR 0001). Architekturtests (z. B. mit Pest Arch): Mapper ohne Datenbankzugriff, keine Modulquerzugriffe an Service-Klassen vorbei.
- AP 1.2 Migrationen für organizations, users, api_keys (Schema, keine Ausgabe von Keys), audit_logs, audit_anchors gemäß Datenmodell. UUIDv7 als BINARY(16), DATETIME(6) UTC.
- AP 1.3 Login mit Argon2id, TOTP-2FA verpflichtend (Login ohne 2FA nur bis zur Einrichtung), Wiederherstellungscodes gehasht. Rollen viewer, operator, admin, release.
- AP 1.4 Auditlog append-only: Anwendungs-DB-Nutzer ohne UPDATE und DELETE auf audit_logs, Trigger BEFORE UPDATE und BEFORE DELETE mit SIGNAL, Hash-Kette (prev_hash, row_hash). Kommando `hub:audit:verify` prüft die Kette.
- AP 1.5 Secret-Handling: verschlüsselte Spalten mit dediziertem Schlüssel außerhalb der Anwendungs-ENV (KMS oder Vault). `.env.example` ohne echte Werte.
- AP 1.6 CI: Tests, statische Analyse (PHPStan Level 8 oder höher), Coding Standard, Migrationstest gegen MariaDB 10.11.

Definition of Done:

- Anwendung startet lokal und in einer Staging-Umgebung (EU-Standort).
- Login nur mit 2FA möglich, Rollen greifen in Policies.
- Auditlog lässt UPDATE und DELETE nachweislich nicht zu (Test vorhanden), `hub:audit:verify` bestätigt eine intakte Kette.
- CI grün, Architekturtests aktiv.

Abhängigkeiten: keine fachlichen. Kann parallel zu Phase 0 laufen.

---

## Phase 2: Capability Registry, Probe-Kommando, Connection-Verwaltung

Ziel: Verwaltung von immoware_connections, Persistierung der gemessenen Serverfähigkeiten, doppelt gesicherte Sperren für alle nicht erlaubten Operationen.

Arbeitspakete:

- AP 2.1 Migrationen immoware_technical_users (Zugangsdaten je technischem Nutzer, Breaker-Zustand), immoware_connections (mit technical_user_id, paired_read_connection_id), capabilities, sync_states, sync_runs, sync_events, external_payloads (monatlich partitioniert, Duplikatsperre nur für Importdateien über dedup_hash), export_schedules.
- AP 2.2 Datenbank-Trigger: capabilities.enabled = 1 nur bei evidence_status IN (VERIFIZIERT, DOKUMENTIERT), tested_at gesetzt, hard_locked = 0. immoware_connections.write_enabled = 1 nur mit write_enabled_by, write_confirmed_by, write_approval_document_id und zwei verschiedenen Personen.
- AP 2.3 HTTP-Client-Decorator (Methoden-Guard): DELETE, MOVE, COPY, PROPPATCH, LOCK, UNLOCK und PUT ohne `If-None-Match: *` brechen unabhängig von jeder Konfiguration mit Exception ab. Unit-Tests für jede gesperrte Methode. Feste Limits: 2 Requests pro Sekunde je Host, Concurrency 2 lesend, 1 schreibend, Backoff mit Jitter bei 429, 503, Timeouts.
- AP 2.4 Kommando `hub:probe {connection}` gemäß Architekturentscheidung Abschnitt 3.2: OPTIONS, PROPFIND Depth 0, ETag-Stabilität (zwei Abfragen im Abstand von 60 Sekunden), If-None-Match-Test im Posteingang (nur bei Connection mit purpose write und ausdrücklicher Bestätigung im Kommando), Server-Fingerprint, Strategie je Collection (sync_token, ctag_etag, etag_only, lastmodified_size_hash, full_hash). Ergebnis in probe_result, server_fingerprint, sync_states.strategy.
- AP 2.5 Wöchentliche Probe per Scheduler. Fingerprint-Wechsel setzt Connection auf degraded, Uploads pausieren, Benachrichtigung an Betrieb. Rückschaltung nur durch Rolle release.
- AP 2.6 Seed der Capabilities mit Belegstatus aus der Recherche: webdav.list, webdav.read, webdav.create (VERIFIZIERT), carddav.read, caldav.read (VERIFIZIERT für Existenz, Schreibrichtung unklar), csv.import.* und datev.import, camt.import (DOKUMENTIERT), webdav.overwrite, webdav.delete, webdav.move, carddav.write, caldav.write (hard_locked = 1).
- AP 2.7 Admin-UI für Connections (Anlegen, Secret setzen, Probe auslösen, Status sehen). Standard write_enabled = false, status paused.

Definition of Done:

- Probe gegen den eigenen Mandanten läuft, Ergebnisse stimmen mit dem manuellen Testprotokoll aus Phase 0 überein.
- Alle hard_locked Capabilities sind sowohl per Trigger als auch per Methoden-Guard gesperrt, Tests belegen beide Schichten.
- Fingerprint-Wechsel führt im Test nachweislich zu degraded.

Abhängigkeiten: Phase 0 (AP 0.4, 0.6), Phase 1.

Offene Informationsbedarfe: Auth-Schema und If-None-Match-Verhalten sind Messergebnisse, keine Annahmen (zu verifizieren am eigenen Mandanten, Ergebnis aus Phase 0 einfließen lassen).

---

## Phase 3: WebDAV-Dokumentenspiegel (lesend)

Ziel: Metadatenspiegel des DMS mit hash-basierter Änderungserkennung, Move-Erkennung und vorsichtigem Soft Delete.

Belegstand: DMS per WebDAV als Netzlaufwerk (VERIFIZIERT), mindestens Ordner Posteingang und Dokumente (VERIFIZIERT), Ordnerumfang darüber hinaus VERMUTET und aus Phase 0 bekannt.

Arbeitspakete:

- AP 3.1 Migrationen document_folders, documents, documents_versions.
- AP 3.2 WebDavDocumentConnector: listChanges über PROPFIND Depth 1 je Ordner nach scan_interval_seconds, Properties getetag, getlastmodified, getcontentlength, resourcetype, getcontenttype. Pause 500 ms zwischen Requests, Concurrency 2.
- AP 3.3 Ordner-Fingerprint (children_fingerprint) als Beschleuniger; wöchentlicher Full Reconcile ignoriert Fingerprints.
- AP 3.4 Änderungserkennung nach persistierter Strategie: neue Version nur bei Änderung von content_hash (falls vorhanden) oder Größe plus lastmodified; ETag-Wechsel ohne Änderung dieser Merkmale als unchanged_meta_noise. content_policy je Ordner: metadata_only Standard, hash_on_change für Posteingang, store nur explizit.
- AP 3.5 Move-Erkennung: fehlende Ressource plus neue Ressource mit identischer Größe und lastmodified, Hash-Vergleich nur wenn alter content_hash vorliegt, sonst moved_probable in die Konfliktqueue (Konflikttyp existiert ab Phase 6, bis dahin als sync_event moved_probable).
- AP 3.6 Soft Delete erst nach zwei aufeinanderfolgenden Läufen mit Fehlen, beide mit erfolgreichem Health-Check und erreichbarem Ordner. Beim ersten Fehlen nur missing_since. Mark-and-Sweep läuft nur bei vollständiger Enumeration der Collection (etag_only, lastmodified_size_hash, full_hash, Full Reconcile), nie nach sync_token, unverändertem CTag oder übersprungenem Ordner-Fingerprint (07-sync-strategy.md Abschnitt 4).
- AP 3.7 Archivierung jeder PROPFIND-Antwort in external_payloads (propfind_xml), Blobs über 64 KB im Blob-Speicher (EU).
- AP 3.8 sync-collection (RFC 6578) nur, wenn die Probe es festgestellt hat.
- AP 3.9 Kommando `hub:replay --from payload` für documents.

Definition of Done:

- Posteingang alle 30 Minuten, Dokumente täglich, Full Reconcile wöchentlich im Nachtfenster laufen stabil über zwei Wochen in Staging gegen den eigenen Mandanten.
- Jede Version ist über sync_events auf detected_by, old_checksum, new_checksum, payload_id zurückführbar.
- Replay aus Payloads erzeugt denselben Spiegelstand (Test).
- Lastprofil dokumentiert (Requests je Lauf, Dauer), Intervalle danach bestätigt oder verlängert.

Abhängigkeiten: Phase 2.

Offene Informationsbedarfe: tatsächlicher Ordnerumfang (zu verifizieren am eigenen Mandanten, Phase 0), ob WebDAV DMS-Metadaten (Objektzuordnung) liefert (nach aktuellem Stand nicht, VERMUTET).

---

## Phase 4: CardDAV-Kontaktspiegel (lesend)

Ziel: Kontakte der freigegebenen Adressbücher als hash-basierter Spiegel.

Belegstand: Kontaktfreigabe repliziert Kontakte auf Geräte (VERIFIZIERT für Existenz), Aufteilung nach Kontakttypen (VERMUTET), Schreibrichtung unklar, "nur lesend" nur VERMUTET.

Arbeitspakete:

- AP 4.1 Migrationen contacts, contacts_versions, companies, contact_identifiers (normalisierte E-Mail- und Telefon-Nachschlagetabelle), contact_merges (mit generierter Spalte is_active und Unique je aktivem Merge), external_mappings.
- AP 4.2 CardDavContactConnector: Discovery ausschließlich über die kopierte Freigabe-URL (keine Well-Known-Discovery voraussetzen), PROPFIND Depth 0 für sync-token und CTag, Strategie laut sync_states, addressbook-multiget in Paketen von 50 hrefs bei 2 Requests pro Sekunde.
- AP 4.3 vCard-Normalisierung (Zeilenenden, Property-Reihenfolge, Entfernen von REV, PRODID, fachfremden X-Properties), SHA-256, REV nur als Hinweis. Telefonnummern nur Ziffern (DOKUMENTIERT: Immoware24 akzeptiert bei Telefonnummern nur Ziffern).
- AP 4.4 Mehrere Adressbücher je als eigene Collection; gleiche UID in zwei Freigaben ergibt zwei external_mappings auf einen Hub-Kontakt, abweichende UID ergibt Duplikatsvorschlag (similarity_hash), kein Merge.
- AP 4.5 Ungültiges Token (403 valid-sync-token oder 410): Cursor verwerfen, voller Depth-1-Vergleich, Auditeintrag.
- AP 4.6 Logischer Merge über merged_into_id mit Rückgängig-Funktion (contact_merges), ausschließlich manuell durch operator.
- AP 4.7 DSGVO: personal_data_erased_at, Löschjob nach konfigurierter Frist (Default 12 Monate nach Ende der letzten Rolle, mit Rechtsanwalt und Steuerberater abzustimmen), Pseudonymisierung von external_payloads mit contains_personal_data.

Definition of Done:

- Vollabgleich der rund 4.600 Kontakte unter zwei Minuten bei 2 Requests pro Sekunde.
- Änderung eines Kontakts in Immoware24 erzeugt genau eine neue Version, unveränderte Kontakte erzeugen keine Version (Test mit Vergleichslauf).
- Kein Schreibzugriff möglich: Methoden-Guard und hard_locked carddav.write getestet.
- Duplikatsvorschläge sichtbar, Merge nur logisch und rückgängig machbar.

Abhängigkeiten: Phase 2. Parallel zu Phase 3 möglich.

Offene Informationsbedarfe: Aufteilung der Kontaktfreigabe nach Typen und Stabilität der vCard UID (zu verifizieren am eigenen Mandanten).

---

## Phase 5: CSV-Import Stammdaten mit Bootstrap

Ziel: Objekte, Gebäude, Einheiten, Kontakte, Rollen, Verträge und Eigentumsverhältnisse aus manuellen CSV-Exporten der Auswertungen, ohne die Konfliktqueue mit dem Erstimport zu fluten.

Belegstand: "Alle aufgeführten Auswertungen lassen sich über den Export-Button in eine CSV-Datei exportieren." (DOKUMENTIERT). Spaltenformat NICHT VERFÜGBAR bis zur Sichtung in Phase 0. Ein dokumentierter CSV-Stammdatenimport in Immoware24 ist VERMUTET und für den Hub ohne Bedeutung, weil nur gelesen wird.

Arbeitspakete:

- AP 5.1 Migrationen properties, buildings, units, contact_roles, contracts, contract_parties, ownerships, bank_accounts (IBAN verschlüsselt, iban_hash, iban_last4) samt Versionstabellen, import_formats.
- AP 5.2 Eingang über Drop-Ordner (SFTP, je Exporttyp ein Unterordner) und Upload in der Hub-UI mit Pflichtmetadaten: Exporttyp, Objekt oder objektübergreifend, Exportdatum, exportierender Mitarbeiter, Vollexport oder Teilexport. Vollständige Archivierung in external_payloads (csv_file), Datei-Hash-Duplikate abweisen.
- AP 5.3 Header-Fingerprint gegen import_formats. Unbekannter Header führt zu Quarantäne und Stopp für diesen Exporttyp. Zeichensatz (UTF-8 mit und ohne BOM, Windows-1252) und Trennzeichen erkennen und validieren.
- AP 5.4 Zeilenweiser row_hash über normalisierte Zellen. Snapshot-Semantik: missing_since bei fehlender Zeile in einem Vollexport für genau dieses Objekt, Soft Delete erst nach dem zweiten Vollexport ohne Treffer. Teilexporte löschen nie.
- AP 5.5 Bootstrap-Verfahren (Architekturentscheidung 4.4): import_formats Version 1 als draft aus den Phase-0-Dateien, `hub:bootstrap --dry-run` mit Kollisionsbericht, Bestätigung des key_schema, `hub:bootstrap --accept-all-exact`. Auto-Link CardDAV zu CSV-Kontakt nur bei exakter Übereinstimmung von E-Mail, Nachname, Vorname und genau einem Kandidaten, markiert created_by = bootstrap, danach deaktiviert.
- AP 5.6 Bootstrap-Bericht als Testprotokoll im Repository.

Definition of Done:

- 67 Objekte, 869 Einheiten, rund 4.600 Kontakte (Stichtag 01.07.2026) sind importiert, Konfliktqueue enthält nur Kollisionen und leere Schlüssel.
- Zweiter Import derselben Datei erzeugt null Versionen; Import einer geänderten Datei erzeugt Versionen nur für geänderte Zeilen.
- Unbekannter Header landet nachweislich in Quarantäne.
- key_schema je Exporttyp bestätigt, import_formats auf active.

Abhängigkeiten: Phase 0 (AP 0.7), Phase 1. Für den Auto-Link zusätzlich Phase 4.

Offene Informationsbedarfe: Spaltenformate, Schlüsselspalten, Stabilität der Objekt- und VE-Nummern über Exporte hinweg (zu verifizieren am eigenen Mandanten).

---

## Phase 6: Konfliktqueue, proposed_change, Datenalter, Export-Erinnerungen

Ziel: Human-in-the-Loop für alle unsicheren Fälle und der einzige Rückweg für nicht belegte Schreibrichtungen.

Arbeitspakete:

- AP 6.1 Migration conflicts mit Konflikttypen uncertain_identity, duplicate_external, duplicate_candidate, moved_probable, local_change_vs_remote, source_mismatch, format_mismatch, write_verify_failed, write_target_exists, write_unknown_unresolved, proposed_change; höchstens ein offener Konflikt je (entity_type, entity_id, conflict_type) über generierte Spalte open_key, Folgeläufe aktualisieren occurrences und last_seen_run_id.
- AP 6.2 Reconciler-Anbindung: Konflikte blockieren nur den betroffenen Datensatz. Statuspfade open, in_progress, resolved_*, dismissed mit Auditeinträgen.
- AP 6.3 proposed_change: Eintrag mit Ziel, Feld, altem Wert, neuem Wert, Begründung. Mitarbeiter setzt manuell in Immoware24 um und schließt den Eintrag (resolved_applied_manually). Der nächste Sync bestätigt über Hash und setzt confirmed_by_sync_run_id.
- AP 6.4 stale_since und source_status (fresh, stale, degraded) auf allen Spiegeldatensätzen; data_age_seconds in jeder UI- und späteren API-Ausgabe.
- AP 6.5 export_schedules mit verantwortlicher Person und Sollrhythmus, Erinnerung bei Überfälligkeit, stale_since auf abhängigen Datensätzen.
- AP 6.6 Operator-UI: Konfliktliste, Zuweisung, Entscheidung, Verlauf.

Definition of Done:

- Ein proposed_change durchläuft im Test den vollständigen Zyklus bis zur Hash-Bestätigung.
- Überfälliger Export erzeugt Erinnerung und stale-Kennzeichnung; erneuter Import hebt sie auf.
- Jede Spiegelausgabe zeigt data_age_seconds und source_status.

Abhängigkeiten: Phasen 3, 4, 5.

---

## Phase 7: Resilienz, Degraded-Mode, DLQ, Backups, Betrieb

Ziel: Betriebsreife des lesenden Hubs vor Beginn des Schreibpfads.

Arbeitspakete:

- AP 7.1 CircuitBreaker je Connection (öffnet nach 5 Fehlern in 2 Minuten, Half-Open nach 10 Minuten), Health-Check erkennt 401 (Passwort-Reset betrifft alle Synchronisationen des Nutzers, VERIFIZIERT) und öffnet den Breaker.
- AP 7.2 dlq_items nach 5 Versuchen, manuelle Wiederaufnahme durch operator.
- AP 7.3 Degraded-Mode vollständig: Lesen läuft weiter, Uploads pausieren, Rückschaltung nur durch release mit Begründung.
- AP 7.4 Backups: MariaDB täglich voll, Binlog kontinuierlich, Blobs versioniert. hub_decision_backups täglich (conflicts, contact_merges, external_mappings manual und bootstrap, import_formats, capabilities, users, api_keys, webhook_endpoints). Wöchentlicher Export der Audit-Kettenwurzel (audit_anchors) an Object-Lock-Speicher.
- AP 7.5 Wiederherstellungstest: Vollrestore und Replay-Restore (`hub:replay --from payload`) getrennt, Protokoll im Repository. Quartalsweise Wiederholung als Betriebsprozess.
- AP 7.6 Monitoring und Alarmierung: Sync-Fehler, Breaker offen, degraded, DLQ-Wachstum, Backup-Ausfall.
- AP 7.7 Exit-Strategie als Betriebsprozess unabhängig vom Hub: monatlich DATEV-Export, CSV-Auswertungen aller Objekte, Dokumentliste per WebDAV sichern (Regelung bei Vertragsende in den AGB laut Snippet nicht belegt, VERMUTET, Prüfung durch Rechtsanwalt aus Phase 0 einfließen lassen).
- AP 7.8 Secret-Rotation dokumentiert und einmal durchgeführt (Freigabe-Passwort des Lesenutzers).

Definition of Done:

- Simulierter Ausfall des DAV-Adapters führt zu stale-Kennzeichnung ohne Datenänderung; Wiederanlauf setzt Läufe fort.
- Vollrestore und Replay-Restore erfolgreich, Protokoll abgelegt.
- Rotation des Freigabe-Passworts ohne Datenverlust durchgeführt.
- Betriebshandbuch (Runbook) mit den Recovery-Szenarien der Architekturentscheidung Abschnitt 8 im Repository.

Abhängigkeiten: Phase 6.

---

## Phase 8: Schreibpfad Posteingang und Freigabeprozess

Ziel: Der einzige Schreibpfad, create-only per WebDAV in den Posteingang, mit Idempotenz, Precheck, If-None-Match und Verifikation.

Belegstand: Upload per WebDAV in den Posteingang ist VERIFIZIERT (Scanner-Upload, "Die Ordner Posteingang und Dokumente können überschrieben werden. Änderungen und Löschvorgänge wirken sich entsprechend auf das eingebundene System aus.", Snippet-Paraphrase). OCR- und KI-Erkennung im Posteingang DOKUMENTIERT (Support-Artikel 5556851032733, Snippet; nicht Teil der VERIFIZIERT-Aussage zum Upload). Automatische Objektzuordnung hochgeladener Dateien NICHT belegt. Papierkorb wird nach 7 Tagen geleert (DOKUMENTIERT).

Arbeitspakete:

- AP 8.1 Migration write_operations (mit intent_key) und Trigger: put_attempts maximal 1, kein Rückweg von sent oder unknown auf queued oder precheck. documents-Zeile eines Uploads wird auf der gekoppelten Lese-Connection (paired_read_connection_id) geführt, damit der Posteingang-Scan kein Duplikat anlegt.
- AP 8.2 WriteOperationService nach Architekturentscheidung Abschnitt 6: idempotency_key = SHA-256(connection_id, content_hash, intent_key), UUIDv7-Suffix des Zielpfads erst nach Schlüsselprüfung (05-write-capabilities.md 3.1); Guards (webdav.create enabled, write_enabled, status active, allowed_write_prefix, Größenlimit Default 25 MB ohne belegte Immoware24-Angabe, Dateiname sanitisiert mit UUIDv7-Suffix); Precheck PROPFIND Depth 0; PUT mit If-None-Match: * (412 wie skipped_exists); Verifikation PROPFIND plus GET und Hash-Vergleich; unknown nur lesend auflösen (drei PROPFIND im Abstand von 5 Minuten, dann failed).
- AP 8.3 Neustartverhalten: write_operations in sent oder unknown werden ausschließlich über PROPFIND weitergeführt.
- AP 8.4 Zweite Connection mit purpose write und eigenem Schreibnutzer. Falls die Freigabe nicht auf den Posteingang beschränkbar ist (Ergebnis Phase 0), wird diese Abhängigkeit im Freigabeprotokoll der Geschäftsführung ausdrücklich benannt und der Schutz liegt vollständig bei den Code-Guards.
- AP 8.5 Freigabeprozess in der UI: write_enabled nur durch zwei Personen (Rolle release als zweite), Verweis auf das Freigabedokument der Geschäftsführung (write_approval_document_id), Auditeintrag.
- AP 8.6 Dateinamenskonvention für den Posteingang dokumentieren (Hub liefert nur die Konvention, keine Zuordnung in Immoware24).
- AP 8.7 Runbook-Eintrag: fehlerhafter Upload wird nie automatisch gelöscht, Konflikteintrag, manuelle Bereinigung im DMS durch berechtigten Mitarbeiter innerhalb von 7 Tagen.

Definition of Done:

- Alle Statusübergänge von write_operations sind getestet, einschließlich unknown, 412, failed_verify und Neustart während PUT.
- Kein Testfall kann ein zweites PUT auf denselben Pfad erzeugen (Trigger und Service).
- Freigabevoraussetzungen erfüllt und protokolliert: schriftliche Bestätigung des Immoware24-Supports, DAV-Modul gebucht, eigener Schreibnutzer, Probe mit If-None-Match bestanden, AGB-Prüfung durch Rechtsanwalt, Freigabe der Geschäftsführung.
- Ohne vollständige Freigabevoraussetzungen bleibt write_enabled = false und die Phase gilt als technisch, nicht als fachlich abgeschlossen.

Abhängigkeiten: Phase 7, Phase 0 (AP 0.2, WAITING_FOR_VENDOR_ACCESS).

Eskalation: Aktivierung des Schreibpfads ist eine haftungsrelevante Erklärung gegenüber dem Livesystem und erfordert die Freigabe der Geschäftsführung.

---

## Phase 9: Pilotbetrieb Schreibpfad und Abnahme

Ziel: Vier Wochen kontrollierter Betrieb mit Testdokumenten und anschließend einer definierten Dokumentklasse.

Arbeitspakete:

- AP 9.1 Woche 1: ausschließlich Testdokumente mit Kennzeichnung im Dateinamen, tägliche Prüfung im DMS durch einen Mitarbeiter, Löschung der Testdokumente manuell im DMS.
- AP 9.2 Prüfen, ob und wie der Posteingang hochgeladene Dateien verarbeitet (OCR, KI-Klassifizierung, Objektzuordnung). Ergebnis als Testprotokoll; automatische Zuordnung gilt bis dahin als NICHT belegt.
- AP 9.3 Wochen 2 bis 4: eine Dokumentklasse (z. B. eingehende Rechnungen aus einem definierten Mailpostfach), Freigabe der Geschäftsführung je Dokumentklasse, falls die Freigabe nicht auf den Posteingang beschränkbar war.
- AP 9.4 Gleichzeitigkeitstest mit Scanner-Upload in den Posteingang, Auswertung von skipped_exists und Konflikten.
- AP 9.5 Abnahmebericht mit Zahlen (Uploads, succeeded, skipped_exists, failed, unknown, Dauer der Verifikation) und Entscheidung über Regelbetrieb.

Definition of Done:

- Vier Wochen ohne Overwrite, Delete oder unaufgelösten unknown-Zustand.
- Abnahmebericht im Repository, Regelbetrieb durch Geschäftsführung freigegeben oder Schreibpfad wieder deaktiviert.

Abhängigkeiten: Phase 8.

---

## Phase 10: DATEV-Buchungsexport und OP-Listen (lesend)

Ziel: Buchungen und offene Posten als Stichtagsdaten im Spiegel.

Belegstand: DATEV-Export als CSV mit Kontenmapping, Festschreibung wählbar, nur Miet- und WEG-Verwaltung, KOST2 (DOKUMENTIERT, Marketingseite, Wortlaut nicht im Original geprüft). Buchungsexport inklusive Belegdokumente seit Update März 2026 (DOKUMENTIERT). Listen offener Posten in der UI (DOKUMENTIERT). Dateiformat der DATEV-Datei aus Phase 0 gesichtet, sonst NICHT VERFÜGBAR.

Arbeitspakete:

- AP 10.1 Migrationen transactions (kind ledger), open_items samt Versionstabellen, invoices.
- AP 10.2 DatevExportConnector als Datei-Import über den Mechanismus aus Phase 5 (Drop-Ordner, Pflichtmetadaten, Header-Fingerprint, Quarantäne). external_id aus row_hash (Belegfeld 1, Buchungsdatum, Konto, Gegenkonto, Betrag, Text) plus occurrence_no (Vorkommenszähler identischer Zeilen innerhalb der Datei); Duplikate werden als Duplikat gespeichert, nicht verschmolzen, Summen je Objekt bleiben abstimmbar.
- AP 10.3 OP-Listen-Import mit as_of_date (Stichtag), Unique (connection_id, external_id_hash, as_of_date).
- AP 10.4 Kontenrahmen-Zuordnung zu properties über Objektnummer, wo die Datei das trägt; sonst identity_confidence derived.
- AP 10.5 Aufbewahrung buchhaltungsrelevanter Payloads 10 Jahre (mit Steuerberater abgestimmt in Phase 0), Ausschluss von der Pseudonymisierung.
- AP 10.6 Abstimmung mit Steuerberater über Nutzen und Format, ob der Hub-Spiegel für Auswertungen tragfähig ist.

Definition of Done:

- Echte DATEV-Datei und OP-Liste des eigenen Mandanten importiert, Summen je Objekt gegen Immoware24-Auswertung abgestimmt (Abstimmprotokoll mit Rechenweg).
- Zweiter Import derselben Datei erzeugt keine Versionen.

Abhängigkeiten: Phase 5, Phase 0 (AP 0.7).

Offene Informationsbedarfe: Dateiformat (EXTF-Buchungsstapel oder anderes), Trennzeichen, Zeichensatz, ob Belegdokumente im Export enthalten sind (zu verifizieren am eigenen Mandanten).

---

## Phase 11: CAMT.053 und optional HeiWaKo (lesend)

Ziel: Bankumsätze zur Anzeige und Abstimmung, optional Heizkosten-Datenaustausch lesend.

Belegstand: Kontoumsatzdateien CAMT.053 v02 und v08, MT940 STA importierbar in Immoware24, manueller Export aus dem Banking-Client (DOKUMENTIERT). Ablösung MT940 durch CAMT bis November 2025 (DOKUMENTIERT). HeiWaKo/bved-Sätze B/K, L/M, D, E898 (DOKUMENTIERT).

Arbeitspakete:

- AP 11.1 BankFileConnector: CAMT.053 v08 (und v02) lesen, external_id aus AcctSvcrRef bzw. EndToEndId plus Betrag und Buchungsdatum, row_hash. Kein Einspielen in Immoware24.
- AP 11.2 Quelle: manueller Export aus dem Banking-Client (Datentresor, Werkzeuge, Datenexport, DOKUMENTIERT) über den Drop-Ordner.
- AP 11.3 Abgleich Bankumsatz gegen OP-Liste als Vorschlag im Hub (kein Writeback, ggf. proposed_change).
- AP 11.4 Optional: HeiWaKo-DTA-Dateien lesen (B/K, L/M als Export, D und E898 als Rückweg) zur Anzeige der Abrechnungsdaten je Objekt. Nur wenn fachlicher Bedarf besteht.

Definition of Done:

- CAMT.053-Datei des eigenen Mandanten importiert, Summen gegen Kontoauszug abgestimmt.
- Kein Schreibpfad in Richtung Banking (Capability nicht vorhanden).

Abhängigkeiten: Phase 10.

Offene Informationsbedarfe: Exportformat des Banking-Clients (CAMT oder proprietär), zu verifizieren am eigenen Mandanten.

---

## Phase 12: CalDAV-Terminspiegel (lesend)

Ziel: Termine aus der Kalenderfreigabe als lesende Quelle mit niedriger Priorität.

Belegstand: Kalenderfreigabe repliziert Kalender auf Geräte (VERIFIZIERT für Existenz). Android-Hinweis "Schreibschutz erzwingen" (VERMUTET, Originaltext nicht gesichtet). Schreibrichtung nicht eingeplant.

Arbeitspakete:

- AP 12.1 CalDavCalendarConnector analog Phase 4: Discovery über Freigabe-URL, sync-token oder CTag, calendar-multiget in Paketen von 50, iCalendar-Normalisierung, UID plus RECURRENCE-ID als external_id.
- AP 12.2 Tabellen calendar_events und Versionen (Datenmodell zu ergänzen, Herkunft-Block H).
- AP 12.3 Verknüpfung zu cases und contacts nur als Vorschlag.

Definition of Done:

- Termine der Freigabe im Spiegel, Änderung erzeugt genau eine Version, caldav.write bleibt hard_locked und getestet gesperrt.

Abhängigkeiten: Phase 4.

---

## Phase 13: Ausgehende Webhooks für benannte Konsumenten (n8n)

Ziel: Ereignisse des Spiegels an genau benannte Konsumenten liefern (siehe `docs/n8n/`).

Belegstand: Eine Immoware24-eigene Webhook- oder API-Schnittstelle ist NICHT VERFÜGBAR; alle Ereignisse stammen aus dem Hub.

Arbeitspakete:

- AP 13.1 Migrationen webhook_endpoints, webhook_outbox, webhook_deliveries.
- AP 13.2 Outbox-Pattern: Ereignisse aus sync_events, conflicts, write_operations in die Outbox; Dispatcher mit HMAC-SHA256 über Body plus Zeitstempel, Replay-Fenster 5 Minuten, Retry mit Backoff.
- AP 13.3 Nutzlast-Rahmen gemäß `docs/n8n/README.md` mit source.evidence_status, detected_by, status, data_age_seconds. Keine Ereignisse für Quellen unter DOKUMENTIERT.
- AP 13.4 Konsument benennen, Zweck und Datenumfang dokumentieren, AV-Vertrag prüfen, Freigabe der Geschäftsführung für Datenweitergabe.
- AP 13.5 Referenzflow in n8n für einen der vier Beispielfälle mit Signaturprüfung und Deduplizierung.

Definition of Done:

- Ein benannter Konsument empfängt signierte Ereignisse, Replay-Test und Duplikattest bestanden.
- HUB_WEBHOOKS_ENABLED bleibt false, solange kein Konsument benannt ist.

Abhängigkeiten: Phase 9 (Schreibpfad stabil), benannter Konsument.

---

## Phase 14: Lesende API mit Scopes, Regelbetrieb, Review

Ziel: Lesende API für Fremdsysteme, Übergabe in den Regelbetrieb, Architektur-Review.

Arbeitspakete:

- AP 14.1 Ausgabe von API-Keys (Argon2id, Prefix, Scopes documents:read, contacts:read, units:read, optional documents:create, allowed_ips, Ablauf, Widerruf).
- AP 14.2 Lesende Endpunkte mit data_age_seconds, stale_since, source_status in jeder Antwort. Keine Ausgabe von IBAN, nur iban_last4 mit eigenem Scope.
- AP 14.3 Optional documents:create als Rückkanal zum Schreibpfad (nur bei write_enabled).
- AP 14.4 Review der ADRs 0001 bis 0003 gegen die Betriebserfahrung; Entscheidung über MCP-Layer, Telefonie-Lookup oder weitere Konsumenten nur bei konkretem Bedarf.
- AP 14.5 Übergabe in den Regelbetrieb: Runbook, Verantwortlichkeiten, Export-Rhythmen, Quartalsprüfungen (Restore, Probe-Ergebnisse, Secret-Rotation), Beobachtung der Immoware24-Release-Notes im Support-Center (kein Vorankündigungskanal belegt).

Definition of Done:

- API mit Scopes im Einsatz für mindestens einen Konsumenten oder bewusst nicht aktiviert (Entscheidung dokumentiert).
- Review-Protokoll und aktualisierte ADRs im Repository.
- Regelbetrieb mit benannten Verantwortlichen.

Abhängigkeiten: Phase 13.

---

## Zusammenfassung der offenen Informationsbedarfe

| Nr. | Punkt | Kennzeichnung | Benötigt ab Phase |
|---|---|---|---|
| 1 | Buchung und Kosten des DAV-Moduls | WAITING_FOR_VENDOR_ACCESS | 0 |
| 2 | Zulässigkeit automatisierter WebDAV-Nutzung durch Serveranwendung, Rate Limits, nicht öffentliche API oder Exportmechanismen | WAITING_FOR_VENDOR_ACCESS | 0 (Blocker für 8) |
| 3 | Auth-Schema des DAV-Endpunkts (Basic oder Digest) | zu verifizieren am eigenen Mandanten | 0, 2 |
| 4 | ETag-Stabilität, sync-collection, CTag, If-None-Match | zu verifizieren am eigenen Mandanten | 0, 2, 8 |
| 5 | Ordnerumfang per WebDAV jenseits Posteingang und Dokumente | zu verifizieren am eigenen Mandanten | 0, 3 |
| 6 | Beschränkbarkeit einer Dateifreigabe auf den Posteingang | zu verifizieren am eigenen Mandanten | 0, 8 |
| 7 | Nutzerrolle, die DAV-Freigaben tragen darf | zu verifizieren am eigenen Mandanten | 0 |
| 8 | Aufteilung der Kontaktfreigabe nach Kontakttypen, Stabilität der vCard UID | zu verifizieren am eigenen Mandanten | 0, 4 |
| 9 | Spaltenformate, Trennzeichen, Zeichensatz, Schlüsselspalten aller CSV-Auswertungen, Verfügbarkeit der OP-Liste über den Export-Button | zu verifizieren am eigenen Mandanten | 0, 5, 10 |
| 10 | Format der DATEV-Exportdatei, Belegdokumente im Export | zu verifizieren am eigenen Mandanten | 0, 10 |
| 11 | Exportformat des Banking-Clients | zu verifizieren am eigenen Mandanten | 11 |
| 12 | Automatische Objektzuordnung hochgeladener Dateien im Posteingang | zu verifizieren am eigenen Mandanten | 9 |
| 13 | AGB-Wortlaut: automatisierter Zugriff, Papierkorb, Regelung bei Vertragsende | Prüfung durch Rechtsanwalt | 0, 7, 8 |
| 14 | Löschfristen personenbezogener Daten, Aufbewahrung buchhaltungsrelevanter Dateien | Abstimmung mit Rechtsanwalt und Steuerberater | 4, 10 |
| 15 | Originalwortlaut der als Snippet-Paraphrase gesicherten Zitate (Anleitung DAV-Adapter, Support-Artikel) | zu verifizieren am eigenen Mandanten | 0 |
| 16 | Benannter Konsument für Webhooks oder API | interne Entscheidung | 13, 14 |

## Punkte, die ausdrücklich nicht gebaut werden

Bidirektionaler Sync für Kontakte und Termine, jede Form von DELETE, MOVE, COPY, Overwrite über WebDAV, UI-Automation oder Scraping der Immoware24-Weboberfläche, Umgehung von 2FA, Event-Sourcing-Infrastruktur, Microservices, Volltextindex über Dokumentinhalte, Auto-Merge von Kontakten, heuristisches CSV-Matching, Token-Bucket-Manager, Adapter für OpenImmo, E-Post, Portal24, craftware24 und Ticketsystem, Public REST-API und MCP-Layer vor stabilem Schreibpfad.
