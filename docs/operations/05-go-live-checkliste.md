# 05 Go-live-Checkliste

Stand: 12.09.2026
Grundlage: `docs/implementation-plan.md` Phase 0 (AP 0.1 bis 0.9), Phase 7, Phase 8 (Definition of Done), Phase 9 (Pilotbetrieb), `docs/immoware/10-test-report.md`.

Jeder Punkt wird mit Datum, Person und Beleg (Dateipfad, Audit-ID, Ticket) abgehakt. Ohne vollständigen Block A bis D geht kein lesender Betrieb live; ohne Block E bleibt der Schreibpfad deaktiviert.

## A. Voraussetzungen (Phase 0)

| Nr. | Punkt | Beleg | Status |
|---|---|---|---|
| A1 | DAV-Modul gebucht, Kosten von der Geschäftsführung freigegeben (AP 0.1) | Freigabeprotokoll im Repository | WAITING_FOR_VENDOR_ACCESS |
| A2 | Schriftliche Antwort des Immoware24-Supports zur Zulässigkeit automatisierter WebDAV-Nutzung und zu Limits (AP 0.2) | Schreiben, Datum der Anfrage | WAITING_FOR_VENDOR_ACCESS |
| A3 | Technische Nutzer angelegt: Lesenutzer und Schreibnutzer, kleinste Rolle mit DAV-Berechtigung (AP 0.3), 2FA am Web-Login bei benannter Person | Nutzerliste ohne Passwörter | offen |
| A4 | Freigaben durch admin im Konfigurationsportal, Beschränkbarkeit auf Posteingang geprüft (AP 0.4), Freigabe-Passwörter im Secret-Store | Testprotokoll 10-test-report.md | offen |
| A5 | Manuelle Probe mit Standard-Client: Ordnerumfang, Kontaktfreigaben (AP 0.5) | Testprotokoll | offen |
| A6 | Technische Probe (AP 0.6): OPTIONS, PROPFIND, Auth-Schema, DAV-Header, sync-token, CTag, ETag-Stabilität 60 s, If-None-Match mit Testdatei (zweites PUT 412), Testdatei manuell im DMS gelöscht | `php artisan hub:probe <connection>`, Testprotokoll | offen |
| A7 | Je Exporttyp eine reale Datei gesichtet, Header und Schlüsselspalten dokumentiert, pseudonymisiert abgelegt (AP 0.7) | `docs/immoware/03-field-mapping.md`, import_formats draft | offen |
| A8 | Quellen gesichert: AGB-Wortlaut, DAV-Anleitung, Support-Artikel als Original (AP 0.8) | Ablage im Repository oder Aktenordner | offen |
| A9 | Rechtliche Prüfung AGB, AV-Vertrag Immoware24, Löschfristen, Aufbewahrung 10 Jahre (AP 0.9) | Stellungnahme Rechtsanwalt und Steuerberater | offen |
| A10 | Rückfallentscheidung dokumentiert: DAV first, Intervalle verlängert oder reiner Datei-Import-Spiegel (ADR 0003) | Ergänzung ADR 0003 | offen |

## B. Infrastruktur

| Nr. | Punkt | Beleg | Status |
|---|---|---|---|
| B1 | Hoster in der EU, AV-Vertrag, Serverdimensionierung freigegeben | Vertrag | offen |
| B2 | DNS `immoware.muellerhv.de`, TLS-Zertifikat, Reverse Proxy mit `X-Forwarded-Proto` | `curl -I https://immoware.muellerhv.de/up` | offen |
| B3 | Deployment nach 01-deployment.md (Variante A oder B), `nginx -t`, erster Docker-Build auf Staging | Deploy-Log | offen |
| B4 | `.env` vollständig, `APP_DEBUG=false`, alle `IMMOWARE_WRITE_*` false, `HUB_ENCRYPTION_KEY_*` auf KMS oder Vault | `php artisan hub:doctor` ohne Fehler | offen |
| B5 | `trustProxies` in `bootstrap/app.php` ergänzt (01-deployment.md Abschnitt 7) | Commit | offen |
| B6 | Worker (2 Prozesse) und Scheduler laufen, `php artisan schedule:list` geprüft, Zeitzone Europe/Berlin | Prozessliste | offen |
| B7 | Backup nach 02-backup-restore.md eingerichtet, erster Lauf erfolgreich, Offsite-Ziel mit Object Lock, GPG-Schlüssel offline verwahrt | Backup-Log, Schlüsselverwahrung | offen |
| B8 | Wiederherstellungstest Vollrestore bestanden | Protokoll 02-backup-restore.md Abschnitt 6 | offen |
| B9 | Monitoring nach 03-monitoring.md: externer Health-Check, Alarme A1 bis A10 aktiv, Testalarm ausgelöst und empfangen | Screenshot oder Log | offen |
| B10 | Erster Hub-Nutzer mit 2FA, Rollen viewer, operator, admin, release je benannter Person, keine Sammelkonten | `hub:user:create`, Auditlog | offen |

## C. Qualität

| Nr. | Punkt | Beleg | Status |
|---|---|---|---|
| C1 | CI grün auf dem Release-Tag: validate, audit, Pint, PHPStan, Tests SQLite und MariaDB | GitHub Actions Lauf | offen |
| C2 | `composer audit` ohne offene Schwachstellen | CI | offen |
| C3 | Migrationen gegen MariaDB 11.4 mit `migrate:fresh` und Rollback-Probe | CI Job tests-mariadb | offen |
| C4 | Testbericht `docs/immoware/10-test-report.md` Abschnitt 6 aktualisiert | Dokument | offen |

## D. Lesender Betrieb, Testreihenfolge 1, 10, 100, 1000, alle

Für jede Entität (document, contact, calendar_event) und jede aktive Connection. Nächste Stufe erst, wenn die vorige ohne Fehler und ohne unerwartete Soft-Deletes abgeschlossen ist. Abbruch bei Fehlerquote über 5 Prozent (`HUB_SYNC_BOOTSTRAP_MAX_ERROR_RATE`).

| Nr. | Stufe | Befehl | Prüfung | Status |
|---|---|---|---|---|
| D1 | Probe | `php artisan hub:probe <connection>` | Capabilities gemessen, Fingerprint gespeichert | offen |
| D2 | 1 Datensatz | `php artisan hub:sync:bootstrap <connection> <entity> --stages=1` | Payload archiviert (SHA-256), Mapping korrekt, `external_id` gesetzt | offen |
| D3 | 10 | `--stages=10` | Hash-Vergleich stabil beim zweiten Lauf (keine Änderungen ohne Änderung), Kollisionsbericht leer | offen |
| D4 | 100 | `--stages=100` | Rate Limit (2 rps) eingehalten, keine 429, Dauer notiert | offen |
| D5 | 1000 | `--stages=1000` | Speicher des Workers unter 256 MB, Chunking greift, Datenalter korrekt | offen |
| D6 | alle | `--stages=alle` oder `hub:sync:run <connection> <entity> --mode=full` | Vollständigkeit gegen Zählung im Standard-Client, Soft-Delete-Quote unter 1 Prozent | offen |
| D7 | Inkrementell | Scheduler 48 Stunden laufen lassen | kein stale, `429_count` 0, DLQ leer, Datenalter unter Schwelle | offen |
| D8 | Störfallübung | DAV-Passwort testweise falsch setzen, Störfall 1 und 2 durchspielen | Breaker öffnet, keine Datenänderung, Wiederanlauf | offen |
| D9 | Abnahme lesender Betrieb durch Geschäftsführung | Protokoll | offen |

## E. Schreibpfad (Phase 8 und 9), Freigabe der Geschäftsführung erforderlich

Der Schreibpfad ist eine haftungsrelevante Erklärung gegenüber dem Livesystem. Aktivierung nur nach dokumentierter Freigabe der Geschäftsführung im Vier-Augen-Prinzip (Rolle release als zweite Person).

| Nr. | Punkt | Beleg | Status |
|---|---|---|---|
| E1 | Alle Freigabevoraussetzungen aus Phase 8 erfüllt: Supportbestätigung (A2), DAV-Modul (A1), eigener Schreibnutzer (A3), If-None-Match-Probe bestanden (A6), AGB-Prüfung (A9) | Freigabedokument mit `write_approval_document_id` | offen |
| E2 | Falls Freigabe nicht auf Posteingang beschränkbar: Abhängigkeit ausdrücklich im Freigabeprotokoll benannt, Schutz liegt bei den Code-Guards | Freigabedokument | offen |
| E3 | Zweite Connection `purpose = write` mit `paired_read_connection_id` | Admin-UI, Audit | offen |
| E4 | Dateinamenskonvention Posteingang dokumentiert, Präfix `HUBTEST_` für Testdokumente | Dokument | offen |
| E5 | `IMMOWARE_WRITE_ENABLED=true`, `IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED=true`, alle hart gesperrten Flags false, `hub:doctor` grün, BootGuard aktiv | `.env` Diff ohne Secrets, Audit | offen |
| E6 | `write_enabled` der Connection durch zwei Personen gesetzt (admin plus release) | Auditlog | offen |
| E7 | Woche 1: nur Testdokumente `HUBTEST_`, tägliche Sichtung im DMS, manuelle Löschung innerhalb 7 Tagen (AP 9.1) | Testprotokoll | offen |
| E8 | Verhalten des Posteingangs (OCR, Klassifizierung, Objektzuordnung) protokolliert; automatische Zuordnung gilt bis dahin als NICHT belegt (AP 9.2) | Testprotokoll | offen |
| E9 | Wochen 2 bis 4: eine Dokumentklasse, Freigabe der Geschäftsführung je Dokumentklasse (AP 9.3) | Freigabe | offen |
| E10 | Gleichzeitigkeitstest mit Scanner-Upload, Auswertung `skipped_exists` (AP 9.4) | Testprotokoll | offen |
| E11 | Abnahmebericht: Uploads, succeeded, skipped_exists, failed, unknown, Verifikationsdauer; vier Wochen ohne Overwrite, Delete, unaufgelösten unknown (AP 9.5) | Bericht im Repository | offen |
| E12 | Entscheidung der Geschäftsführung: Regelbetrieb oder Schreibpfad wieder deaktivieren | Beschluss | offen |

## F. Nach Go-live

- Wöchentlicher Betriebsbericht an die Geschäftsführung: Datenalter, DLQ, 429, Breaker-Ereignisse, Backup-Status, offene Konflikte.
- Monatliche Exit-Sicherung unabhängig vom Hub: DATEV-Export, CSV-Auswertungen aller Objekte, Dokumentliste per WebDAV (AP 7.7).
- Quartalsweise: Wiederherstellungstest, Rechteprüfung der Hub-Nutzer, Prüfung der Logs auf Secrets.
- Halbjährlich: Rotation des Freigabe-Passworts (Runbook Störfall 2).
