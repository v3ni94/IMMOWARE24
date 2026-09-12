# 10 Testprotokoll Immoware Hub

Stand: 12.09.2026
Gesellschaft: Hausverwaltung Müller GmbH (Mandant Immoware24)
Status: Automatisierte Tests gegen simulierte Server grün (Abschnitt 6). Es wurden noch keine Tests am eigenen Mandanten durchgeführt.

## 0. Grundsätze

- Alle Tests am Mandanten erfolgen ausschließlich mit eigenem, autorisiertem Zugang über einen dedizierten technischen Nutzer der Hausverwaltung Müller GmbH. Keine Umgehung von Authentifizierung oder Zwei-Faktor-Authentifizierung, kein Scraping der Web-Oberfläche.
- Voraussetzung für Tests am Mandanten: Buchung des DAV-Moduls (VERIFIZIERT als buchbar über Support oder Vertrieb, Quelle https://support.immoware24.de/hc/de/articles/360010876038), Anlage der Freigaben durch den Benutzer admin im Konfigurationsportal (VERIFIZIERT, https://config.dav.immoware24.de/login), schriftliche Bestätigung des Immoware24-Supports zur Zulässigkeit automatisierten Zugriffs (NICHT VERFÜGBAR, WAITING_FOR_VENDOR_ACCESS), Freigabe der Geschäftsführung.
- Jeder Test wird zuerst gegen den Mock-Server (Abschnitt 3) gefahren, erst danach gegen den Mandanten.
- Testdokumente für den Schreibpfad tragen das Präfix HUBTEST_ und werden innerhalb von 7 Tagen manuell durch einen berechtigten Mitarbeiter aus dem DMS entfernt, weil der DMS-Papierkorb laut AGB nach 7 Tagen automatisch geleert wird (DOKUMENTIERT, Wortlaut nicht geprüft, https://www.immoware24.de/agb/). Der Hub sendet nie DELETE.
- Jede Aussage über das Verhalten von Immoware24, die aus einem Test hervorgeht, wird mit Datum, Connection, sync_run_id und Rohantwort (external_payloads, payload_type = probe) belegt und hebt den betroffenen Punkt von VERMUTET oder NICHT VERFÜGBAR auf VERIFIZIERT (am eigenen Mandanten gemessen).

## 1. Testprotokoll-Vorlage (je Testfall)

```
Testfall-ID:           T-<Bereich>-<Nr>
Datum:                 TT.MM.JJJJ
Tester:                Name, Rolle (operator, admin, release)
Umgebung:              mock | mandant
Connection:            Name, connector_type, purpose (read | write)
Technischer Nutzer:    Benutzername (kein Passwort im Protokoll)
Stufe:                 1 | 10 | 100 | 1000 | alle
Vorbedingung:          ...
Schritte:              1. ...
                       2. ...
Erwartetes Ergebnis:   ...
Tatsächliches Ergebnis: ...
sync_run_id:           ...
Belege:                external_payloads.id der Rohantworten, Screenshots (ohne Zugangsdaten)
Bewertung:             bestanden | nicht bestanden | blockiert
Auswirkung auf Belegstand: Punkt X von VERMUTET auf VERIFIZIERT (am eigenen Mandanten) | keine
Folgemaßnahme:         ...
Freigabe zur nächsten Stufe: ja | nein, durch: Name, Datum
```

## 2. Testplan Mandant (Status je Testfall)

Legende Status: OFFEN (noch nicht ausgeführt), BLOCKIERT (Voraussetzung fehlt), BESTANDEN, NICHT BESTANDEN.

### 2.1 Phase 0, Probe und Voraussetzungen

| ID | Testfall | Klärt | Status |
|---|---|---|---|
| T-P0-01 | DAV-Modul gebucht, Kosten freigegeben | Voraussetzung | BLOCKIERT, WAITING_FOR_VENDOR_ACCESS |
| T-P0-02 | Technischer Lesenutzer angelegt, kleinste Rolle mit DAV-Berechtigung ermittelt | Welche Nutzerrolle darf DAV-Freigaben tragen (NICHT VERFÜGBAR) | OFFEN |
| T-P0-03 | Technischer Schreibnutzer angelegt, Dateifreigabe nur Posteingang | Beschränkbarkeit der Freigabe auf einen Ordner (VERMUTET) | OFFEN |
| T-P0-04 | OPTIONS und PROPFIND Depth 0 auf Freigabe-Root | DAV-Klassen, Report-Set, sync-token, CTag, Auth-Schema, Server-Header (alle NICHT VERFÜGBAR) | OFFEN |
| T-P0-05 | Zwei PROPFIND Depth 1 im Abstand von 60 Sekunden | ETag-Stabilität (NICHT VERFÜGBAR) | OFFEN |
| T-P0-06 | PUT HUBTEST_ mit If-None-Match: *, zweites PUT identisch | 412-Verhalten (NICHT VERFÜGBAR) | OFFEN, nur Schreib-Connection, manuelle Entfernung der Testdatei |
| T-P0-07 | PROPFIND Depth 1 rekursiv über Freigabe-Root | Tatsächlicher Ordnerumfang, Existenz Posteingang und Dokumente (VERIFIZIERT), weitere Ordner (VERMUTET) | OFFEN |
| T-P0-08 | CardDAV-Freigabe: PROPFIND Depth 0 und 1, addressbook-multiget mit 5 hrefs | Anzahl Adressbücher, Kontakttypen (VERMUTET), UID-Vorhandensein | OFFEN |
| T-P0-09 | CalDAV-Freigabe: PROPFIND Depth 0 und 1 | CTag, sync-token, Kalenderanzahl | OFFEN |
| T-P0-10 | Je eine echte CSV-Auswertung (Mieter- und VE-Stammdaten, Kontakte, OP-Liste) und eine DATEV-Datei exportieren | Header, Trennzeichen, Zeichensatz, key_schema (NICHT VERFÜGBAR) | OFFEN |
| T-P0-11 | Schriftliche Supportanfrage zu automatisiertem WebDAV-Zugriff, Rate Limits, Schreibrichtung CardDAV/CalDAV | Zulässigkeit, Limits (NICHT VERFÜGBAR) | BLOCKIERT, WAITING_FOR_VENDOR_ACCESS |
| T-P0-12 | Original der Anleitung DAV-Adapter, Support-Artikel und AGB aus Umgebung ohne Egress-Sperre sichern und im Repository ablegen | Zitatwortlaut (bisher nur Snippets) | OFFEN |

### 2.2 Phase 1, Lesepfad

| ID | Testfall | Stufe | Status |
|---|---|---|---|
| T-R-01 | WebDAV Posteingang, eine Datei, Metadaten-Spiegel, Replay | 1 | OFFEN |
| T-R-02 | WebDAV, zehn Dateien, eine in Immoware24 manuell ersetzt, Erkennung updated, Rest unchanged | 10 | OFFEN |
| T-R-03 | WebDAV, hundert Dateien, eine verschoben (hash_on_change), eine gelöscht, zwei Läufe | 100 | OFFEN |
| T-R-04 | WebDAV, tausend Dateien, Lastprofil, keine 429 | 1000 | OFFEN |
| T-R-05 | WebDAV Full Reconcile gesamte Freigabe im Nachtfenster | alle | OFFEN |
| T-R-06 | CardDAV, ein Kontakt, vCard-Normalisierung, Hash | 1 | OFFEN |
| T-R-07 | CardDAV, zehn Kontakte, einer in Immoware24 geändert (Telefonnummer) | 10 | OFFEN |
| T-R-08 | CardDAV, hundert Kontakte, einer gelöscht, zwei Läufe, Soft Delete | 100 | OFFEN |
| T-R-09 | CardDAV, tausend Kontakte, multiget 50er Pakete | 1000 | OFFEN |
| T-R-10 | CardDAV, alle rund 4.600 Kontakte, Bootstrap dry_run, Kollisionsbericht | alle | OFFEN |
| T-R-11 | CSV-Import Stammdaten, eine Zeile, Header-Fingerprint, import_formats draft | 1 | OFFEN |
| T-R-12 | CSV-Import, Vollexport eines Objekts, dann zweiter Vollexport mit einer entfernten Einheit, zwei Snapshots | 10 bis 100 | OFFEN |
| T-R-13 | CSV-Import, Duplikatdatei (identischer Datei-Hash) wird abgewiesen | 1 | OFFEN |
| T-R-14 | CSV-Import, abweichender Header führt zu Quarantäne format_mismatch | 1 | OFFEN |
| T-R-15 | CSV-Import alle 67 Objekte, 869 Einheiten, Bootstrap accept_all_exact | alle | OFFEN |
| T-R-16 | Health-Check mit falschem Passwort, 401, Breaker öffnet, stale_since gesetzt | 1 | OFFEN, nur Mock oder mit ausdrücklicher Freigabe am Mandanten |
| T-R-17 | Server-Fingerprint künstlich geändert (Mock), Connection degraded, Uploads pausieren | 1 | OFFEN, nur Mock |

### 2.3 Phase 2, Schreibpfad (nur Posteingang, nur nach Freigabe der Geschäftsführung)

| ID | Testfall | Stufe | Status |
|---|---|---|---|
| T-W-01 | Upload HUBTEST_ Datei, Precheck, PUT If-None-Match, Verifikation Länge und Hash, status succeeded | 1 | BLOCKIERT bis Freigabe |
| T-W-02 | Zweiter Upload mit identischem Idempotenzschlüssel, kein Netzwerkzugriff, bestehendes Ergebnis | 1 | BLOCKIERT |
| T-W-03 | Upload auf existierenden Zielpfad, Precheck erkennt Datei, skipped_exists | 1 | BLOCKIERT |
| T-W-04 | Zehn Uploads, alle verifiziert, Dateinamen sanitisiert, UUID-Suffix | 10 | BLOCKIERT |
| T-W-05 | Upload in Ordner außerhalb allowed_write_prefix wird durch Guard abgewiesen, kein Request | 1 | OFFEN, Mock ausreichend |
| T-W-06 | Methoden-Guard: DELETE, MOVE, COPY, PROPPATCH, PUT ohne If-None-Match werfen Exception | 1 | OFFEN, Unit-Test |
| T-W-07 | Netzwerkabbruch nach PUT (Mock), status unknown, Auflösung nur per PROPFIND, kein zweites PUT | 1 | OFFEN, nur Mock |
| T-W-08 | Hub-Neustart mit write_operation in status sent, Fortsetzung nur per PROPFIND | 1 | OFFEN, nur Mock |
| T-W-09 | Erkennung der hochgeladenen Datei durch OCR/KI im Posteingang und Zuordnung zu Objekt | 1 | BLOCKIERT, Verhalten NICHT belegt, nur Beobachtung am Mandanten |

## 3. Mock-Server tests/mock-immoware

Zweck: reproduzierbare Tests aller Adapter, Retry-Stufen, Breaker und Guards ohne Zugriff auf den Mandanten. Der Mock bildet ausschließlich das nach, was als VERIFIZIERT oder DOKUMENTIERT gilt, plus konfigurierbare Fehlerfälle. Er ist keine Aussage über das tatsächliche Verhalten des Immoware24-DAV-Servers; alle Annahmen im Mock sind als solche im Code kommentiert (Kennzeichnung "Simulation auf Basis belegter Aussagen, kein Nachbau nicht dokumentierter Immoware24-Interna") und in Phase 0 gegen die Probe abzugleichen.

Änderungsvermerk 12.09.2026: Der Mock ist umgesetzt. Abweichend vom ursprünglichen Plan (Docker-Container, YAML-Szenarien, Technologie offen) ist er ein eigenständiger PHP-Built-in-Server (`tests/mock-immoware/server.php`, Start über `php -S` oder `php artisan hub:mock-immoware:serve`), Szenarien werden je Request per Header, Query oder Pfadpräfix gewählt. Grund: keine neuen Abhängigkeiten, Start innerhalb der PHPUnit-Suite per Process, identische Laufzeit wie der Hub. Die Abschnitte 3.1, 3.2, 3.4 und 3.5 sind entsprechend angepasst, die Antwortmatrix in 3.3 bleibt als Sollverhalten gültig.

### 3.1 Aufbau

```
tests/mock-immoware/
  server.php                Router für den PHP-Built-in-Server (WebDAV, CardDAV, CalDAV, Szenarien, Protokoll)
  README.md                 Endpunkte, Szenarien, Umgebungsvariablen
  fixtures/
    files/Posteingang/      synthetische Dateien mit Präfix HUBTEST_, keine Echtdaten
    files/Dokumente/        inkl. Unterordner Objekt-Musterstrasse-1
    addressbooks/kontakte/  drei synthetische vCards (Eigentümer, Mieter, Handwerker)
    calendars/termine/      zwei synthetische iCalendar-Termine
tests/Contract/
  DavContractTestCase.php   Strukturprüfung eines laufenden DAV-Servers, JSON-Fingerprint, Snapshot-Vergleich
  DavServerContractTest.php Ziel aus CONTRACT_DAV_BASE_URL (Mock oder Mandant), ohne Variable Skip
  MockServerContractTest.php startet den Mock per Process auf freiem Port, Snapshot snapshots/mock.json
  MockScenarioTest.php      Fehlerszenarien gegen echten WebDavConnector und CardDavConnector
  MockDavResponsesTest.php  Prüfung der Http::fake-Bausteine
  snapshots/*.json          Fingerprint des letzten Laufs je Ziel
app/Modules/Connector/Testing/
  MockDavResponses.php      wiederverwendbare Http::fake-Antworten (Multistatus-Builder, vCard, iCal, Fehlerfälle)
  Console/MockImmowareServeCommand.php  hub:mock-immoware:serve, nur local und testing
```

Per PUT angelegte Dateien, das Request-Protokoll und der ETag-Zähler liegen im Laufzeitverzeichnis (`MOCK_RUNTIME_DIR`, Standard im Temp-Verzeichnis), nicht im Repository.

Datenschutz: Fixtures enthalten ausschließlich synthetische Daten. Keine Kontakte, Objekte oder Dokumente der Hausverwaltung Müller GmbH werden in den Mock kopiert.

### 3.2 Annahmen im Mock (aus Probe-Ergebnis abzugleichen)

| Merkmal | Verhalten im Mock | Bezug |
|---|---|---|
| auth_scheme | Basic, 401 mit `WWW-Authenticate: Basic realm=...` | Auth-Schema am Mandanten VERMUTET |
| etag_stable | stabil (SHA-256 des Inhalts, starkes ETag in Anführungszeichen); Szenario `etagunstable` wechselt je Request | ETag-Stabilität NICHT VERFÜGBAR |
| sync_token | nicht unterstützt, REPORT sync-collection antwortet 403 | RFC 6578 NICHT VERFÜGBAR |
| ctag | vorhanden (`CS:getctag`, abgeleitet aus den ETags der Ressourcen) | NICHT VERFÜGBAR |
| if_none_match | ausgewertet: PUT mit `If-None-Match: *` auf vorhandene Ressource 412, ohne Header 412 und Protokolleintrag als Verstoß | NICHT VERFÜGBAR |
| folders_exposed | Posteingang, Dokumente (mit Unterordner) | VERIFIZIERT als mindestens vorhanden, weitere VERMUTET |
| addressbooks | ein Adressbuch `kontakte` mit drei Kontakttypen über CATEGORIES | Aufteilung nach Kontakttypen VERMUTET |
| rate_limit | kein eigenes Limit; Szenario `ratelimited` liefert 429 mit `Retry-After: 10` | Limits NICHT VERFÜGBAR |
| DAV-Header | `DAV: 1, 3` (plus `addressbook` bzw. `calendar-access`), keine Klasse 2 | Server-Header NICHT VERFÜGBAR |

### 3.3 Antwortmatrix

| Statuscode | Auslöser im Mock | Erwartetes Hub-Verhalten |
|---|---|---|
| 200 | PROPFIND, GET, REPORT erfolgreich (207 für Multistatus) | normale Verarbeitung |
| 201 | PUT auf neuen Pfad | status sent, dann verifying |
| 401 | falsches Passwort, Szenario secret_rotated | kein Retry, Breaker öffnet, stale_since, Audit-Eintrag, Hinweis Secret-Rotation |
| 403 | Freigabe fehlt, Szenario no_share; auch valid-sync-token-Fehler | kein Retry, Konflikt bzw. Connection degraded; bei Token-Fehler Cursor verwerfen und Depth-1-Vergleich |
| 404 | Collection entfernt (Freigabe geändert) oder Einzelressource fehlt | Collection: Connection degraded, kein Soft Delete; Ressource: missing_since, Mark-and-Sweep |
| 409 | PUT in nicht existierenden Zielordner | kein Retry, Konflikt, Pfad-Allowlist prüfen |
| 412 | PUT mit If-None-Match auf existierende Ressource | skipped_exists, Konflikt write_target_exists |
| 429 | Szenario rate_limit, mit und ohne Retry-After | Retry-Stufen 30s/2m/10m/30m, Retry-After respektiert, dann DLQ |
| 500 | Szenario server_error, konfigurierbare Häufigkeit | Retry-Stufen, Breaker nach 5 Fehlern in 2 Minuten |
| 503 | Szenario maintenance | wie 500 |
| Timeout | Szenario latency mit 35 Sekunden bei Client-Timeout 30 Sekunden | Retry-Stufen; nach PUT: status unknown, Auflösung nur per PROPFIND |
| Verbindungsabbruch nach PUT-Body | Szenario drop_after_put | status unknown, drei PROPFIND im Abstand von 5 Minuten (im Test verkürzt), kein zweites PUT |
| Antwort mit geändertem Server-Header oder DAV-Header | Szenario fingerprint_change | Connection degraded, Uploads pausieren, Lesen läuft |

### 3.4 Szenarien

Auswahl je Request über Header `X-Mock-Scenario`, Query `?scenario=` oder Pfadpräfix `/s/<szenario>/dav/...` (für Connections mit fester Basis-URL).

| Szenario | Verhalten | Geprüftes Hub-Verhalten (MockScenarioTest) |
|---|---|---|
| ok | Normalbetrieb | WebDAV-Spiegel liest alle Fixtures mit OPTIONS und PROPFIND, CardDAV spiegelt drei Kontakte mit PROPFIND und REPORT, keine Verstöße im Protokoll |
| unauthorized | 401 | Breaker öffnet sofort, weitere Requests werden mit CircuitOpenException abgebrochen, CardDAV meldet ConnectorException |
| forbidden | 403 | fachliches Signal, Breaker bleibt geschlossen, CardDAV meldet ConnectorException |
| notfound | 404 | wie forbidden |
| conflict | 409 | PUT liefert 409, Breaker bleibt geschlossen |
| ratelimited | 429 mit Retry-After 10 | RateLimitManager halbiert die Rate (throttled), Breaker bleibt wegen Retry-After geschlossen |
| servererror | 500 | fünf Fehler öffnen den Breaker, sechster Request erreicht den Server nicht |
| timeout | Antwort erst nach MOCK_TIMEOUT_SLEEP (Test: 3 s bei Client-Timeout 1 s) | ConnectionException, timeout_count 1, Drosselung, ein Breaker-Fehler |
| invalidxml | 207 mit unvollständigem XML | ConnectorException in WebDavClient und CardDAV-Client statt leerer Collection (kein Sweep) |
| slow | Antwort nach 2 s | Latenzmessung, nur manuell |
| etagunstable | ETag wechselt je Request | zwei PROPFIND liefern unterschiedliche ETags |

Nicht umgesetzt gegenüber dem ursprünglichen Plan: Verbindungsabbruch nach PUT-Body (drop_after_put), Server-Header-Wechsel (fingerprint_change), Massenverlust von Ressourcen (mass_missing), CSV-Varianten. Diese Fälle sind über `Http::fake` mit `MockDavResponses` in den Modultests abgedeckt bzw. für den Mock offen (Abschnitt 5).

### 3.5 Abnahmekriterien Mock

- Contract- und Szenario-Tests laufen in der CI bei jedem Lauf von `php artisan test` (Testsuite `Contract`, rund 7 Sekunden). Der Mock wird dafür per Process auf einem freien Port gestartet.
- Kein Szenario darf ein DELETE, MOVE, COPY, PROPPATCH, MKCOL, LOCK, UNLOCK oder ein PUT ohne `If-None-Match: *` vom Hub empfangen. Der Mock protokolliert jede eingehende Methode (`/__mock/log`); jeder Eintrag mit `violation` lässt den Test fehlschlagen. Stand 12.09.2026: keine Verstöße.
- Der Fingerprint des DAV-Servers (DAV-Klassen, Allow, Auth-Schema, Properties, ETag-Format, CTag, Reports) wird als JSON unter `tests/Contract/snapshots/` gespeichert und mit dem letzten Lauf verglichen. Abweichung bedeutet Test rot mit Diff; Erneuerung nur bewusst mit `CONTRACT_SNAPSHOT_UPDATE=1` und Anpassung des Belegstands in `docs/immoware/`.
- Derselbe Contract-Test läuft in Phase 0 gegen den Mandanten (`CONTRACT_DAV_BASE_URL`, `CONTRACT_DAV_USER`, `CONTRACT_DAV_PASS`, Pfade über `CONTRACT_DAV_FILES_PATH`, `CONTRACT_DAV_ADDRESSBOOK_PATH`, `CONTRACT_DAV_CALENDAR_PATH`). Er sendet ausschließlich OPTIONS, PROPFIND und REPORT. Der Unterschied zwischen `snapshots/mock.json` und dem Mandanten-Snapshot ist die Liste der zu korrigierenden Annahmen.
- put_attempts überschreitet in keinem Szenario den Wert 1 je write_operation (geprüft in den Documents-Tests, im Mock über das PUT-Protokoll).

## 4. Ergebnisübersicht (fortzuschreiben)

| Datum | Testfall | Umgebung | Bewertung | Belegstand geändert | Bemerkung |
|---|---|---|---|---|---|
| noch keine Einträge | | | | | |

## 5. Offene Punkte

| Punkt | Status | Klärung |
|---|---|---|
| Zugang zum Mandanten für Tests | WAITING_FOR_VENDOR_ACCESS | DAV-Modul buchen, Freigaben durch admin, GF-Freigabe |
| Zulässigkeit automatisierten Zugriffs | WAITING_FOR_VENDOR_ACCESS | schriftliche Antwort Immoware24-Support, Prüfung Rechtsanwalt |
| Alle Feature-Flags in Abschnitt 3.2 | NICHT VERFÜGBAR bzw. VERMUTET | Probe T-P0-04 bis T-P0-09 am eigenen Mandanten |
| Spaltenformat CSV und DATEV | NICHT VERFÜGBAR | T-P0-10 |
| Automatische Objektzuordnung hochgeladener Dateien im DMS | NICHT VERFÜGBAR | T-W-09, nur Beobachtung |
| Technologie des Mock-Servers | entschieden 12.09.2026: PHP-Built-in-Server, siehe Abschnitt 3 | Szenarien drop_after_put, fingerprint_change, mass_missing im Mock noch offen |

## 6. Automatisierte Tests

Stand 12.09.2026 (Änderungsvermerk 12.09.2026, Integrationslauf nach Admin-, Mcp-, Contract- und Betriebsarbeiten), Lauf `php artisan test` auf Branch claude/vibrant-lovelace-c624qw: **427 Tests, 3.281 Assertions, 420 bestanden, 7 übersprungen** (DavServerContractTest ohne `CONTRACT_DAV_BASE_URL`), Laufzeit rund 14 Sekunden. Die Modultabelle unten (Summe 284) umfasst die Fachmodule; hinzu kommen Admin 91 Tests (708 Assertions), Mcp 14 Tests (207 Assertions) und Testsuite Contract 38 Tests (136 Assertions, 7 Skips). `vendor/bin/pint --test` sauber, `phpstan analyse --no-progress` (Level 5) ohne Fehler. Umgebung: PHPUnit, `RefreshDatabase`, SQLite in-memory, Queue `sync`, Cache `array`, alle Schreib-Flags false, `HUB_BOOT_GUARD=true`. HTTP nach außen ausschließlich über die `Http`-Facade mit `Http::fake()`, es wurde kein Immoware24-System kontaktiert.

Zahlen je Modul (Filter `Feature\<Modul>|Unit\<Modul>`, Summe 284):

| Modul | Tests | Assertions | Abdeckung (Auswahl) |
|---|---|---|---|
| Security | 58 | 281 | TOTP nach RFC 6238 (SHA1, 6 und 8 Stellen, Fenster), Base32, Login-Sperre nach Fehlversuchen, 2FA-Challenge und Recovery-Codes, API-Key-Middleware (Bearer, Scopes, IP-Bindung, Ablauf, Widerruf, Rate Limit), Audit-Hash-Kette (append-only, Verifikation, Anker), Rollen und Gates, Sitzungsverwaltung, Console-Commands, Schreib-Flags (Default false, 403 problem+json beim Upload, BootGuard bei DELETE-Flag true) |
| Connector | 47 | 252 | DAV-Multistatus-Parser, ConnectorManager (alle fünf Adapter registriert, Ablehnung unbekannter Namen, Zugangsdaten nur im Speicher), CapabilityRegistry (Hard Locks, Config-Flags, Teststatus), RateLimitManager (Token-Bucket, 429-Rückmeldung), CircuitBreaker (Zustände, Halboffen), HttpClientFactory (blockierte Methoden, User-Agent), RemoteRequestLogger (Maskierung, Prune), Probe (OPTIONS, PROPFIND, ETag-Stabilität, Auth-Erkennung) und `hub:probe` |
| Documents | 23 | 243 | Multistatus mit Umlauten und URL-Encoding, Ordner- und Dokumentspiegel, Sweep als Soft Delete mit Restore, ETag-Wechsel, Move-Erkennung über content_hash, Chunking mit Cursor, Posteingang-Upload idempotent (kein zweites PUT), Timeout → unknown, Auflösung nur per PROPFIND, 412, Precheck, Präfix- und Größengrenzen, Dry-Run, DELETE/MOVE/COPY/MKCOL werfen WriteBlockedException, Audit-Einträge |
| Contacts | 25 | 169 | vCard 2.1/3.0/4.0 (Quoted-Printable, Faltung, fehlende UID, mehrere TEL-Typen), Mapper, CardDAV-Pull (CTag unverändert → nur PROPFIND, ETag-Diff, Multiget-Batches, sync-collection), Sweep ohne Hard Delete, Duplikatvorschläge ohne Merge, Rollen nur mit Mapping-Regel, Schreibpfad gesperrt |
| Calendar | 8 | 55 | iCalendar-Parser (TZID, ganztägig, UTC mit DURATION, RRULE, RECURRENCE-ID), CalDAV-Pull, Schreibpfad gesperrt |
| Sync | 34 | 326 | RunSyncJob (Chunk-Loop, Cursor, Lock, Skip bei laufendem Full Sync, Fehler → DLQ), DLQ (Retry, Ignore, Replay), Konflikte und Auflösung, proposed_change, Field-Mapping-Versionen, Payload-Archiv (Maskierung, gzip, Prune), Datenalter und Stale, Bootstrap-Stufen, Zeitpläne, Backoff |
| Imports | 32 | 209 | CSV-Reader (Windows-1252, BOM, Trennzeichen), Header-Fingerprint und Formatbestätigung, Drop-Ordner mit Sidecar und Quarantäne, Stammdaten-Importer, OP-Snapshot, DATEV-EXTF (Vorzeichen, Duplikate), CAMT.053 v02 und v08, Export-Erinnerungen, Export-Job |
| Api | 21 | 449 | Directory-Antwort, Pagination und Provenance, Filter und Sortierung, RFC-7807-Fehler, Scopes (403), Cases (POST, PATCH, Idempotency-Key), Kontakt-PATCH → 202 proposed_change, Upload 403 bei Flag false, Directory (json, vcf, xml) ohne Notizen, Health-Endpunkte, OpenAPI-Generator, vCard-Writer |
| Webhooks | 8 | 72 | Outbox und Zustellungen je Endpunkt, HMAC-Signatur `t=<ts>,v1=<hex>` und Replay-Fenster, Retry-Plan und DLQ, Endpunkt-API mit Scope |
| Core | 25 | 115 | BootGuard (alle hart gesperrten Flags, String-true, Boot-Abbruch im Prozess), Migrationen auf SQLite, Formatter (Datum, Beträge), Checksummen und externe Identität (Trait), SecretMasker, `hub:doctor` (Exit-Code 0 ohne Redis, 1 bei Flag-Verletzung) |
| EndToEnd | 3 | 49 | WebDAV-Multistatus mit drei Dateien → RunSyncJob synchron → `GET /api/v1/documents` mit Scope documents:read liefert drei Dokumente mit Provenance, Outbox `document.created`; CardDAV mit zwei vCards → `/api/v1/contacts`, `/api/v1/directory`, vcf-Suche, Outbox `contact.created`; fehlgeschlagener Lauf → SyncRun failed, DLQ-Eintrag, Outbox `sync.failed` |

Nicht durch automatisierte Tests abgedeckt: Verhalten des echten Immoware24-DAV-Servers (Abschnitt 3.2), MariaDB-spezifisches Verhalten (Tests laufen auf SQLite, Migrationen sind für beide Systeme geschrieben), Redis-Locks im Mehrprozessbetrieb, Mailversand, Docker-Build und nginx-Konfiguration (kein Daemon in der Entwicklungsumgebung). Die Admin-Oberfläche ist seit 12.09.2026 durch tests/Feature/Admin abgedeckt (Änderungsvermerk). Diese Punkte sind Bestandteil der Phasen 0 und 9 am eigenen Mandanten.

