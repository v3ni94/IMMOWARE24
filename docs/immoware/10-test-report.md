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

Stand 12.09.2026 nach dem Fix-Lauf (Abschlusslauf nach vier parallelen Fix-Bereichen), Lauf `php artisan test` auf Branch claude/vibrant-lovelace-c624qw: **554 Tests, 4.223 Assertions, 546 bestanden, 8 übersprungen, 0 fehlgeschlagen**, Laufzeit rund 18 Sekunden. Übersprungen: 7 Tests der Testsuite Contract (DavServerContractTest ohne `CONTRACT_DAV_BASE_URL`) und der MariaDB-Zweig von MariaDbTriggersMigrationTest (läuft nur im CI-Job tests-mariadb). Vor dem Fix-Lauf waren es 427 Tests mit 3.281 Assertions. `vendor/bin/pint --test` auf dem gesamten Repository sauber, `phpstan analyse --no-progress` (Level 5) ohne Fehler, `php artisan migrate:fresh --env=testing` auf SQLite in-memory vollständig, `php artisan route:list --except-vendor` ohne doppelte Methode-Pfad-Kombinationen oder Routennamen (138 Routen), `hub:openapi:export` und `hub:mcp:export` regeneriert. Umgebung: PHPUnit, `RefreshDatabase`, SQLite in-memory, Queue `sync`, Cache `array`, alle Schreib-Flags false, `HUB_BOOT_GUARD=true`, `HUB_API_KEYS_ENABLED=true` nur in phpunit.xml (sonst antworten alle Key-Endpunkte 503). HTTP nach außen ausschließlich über die `Http`-Facade mit `Http::fake()`, es wurde kein Immoware24-System kontaktiert.

Zahlen je Modul (Filter `Feature\<Modul>|Unit\<Modul>`, Summe 554):

| Modul | Tests | Assertions | Abdeckung (Auswahl, Ergänzungen des Fix-Laufs kursiv) |
|---|---|---|---|
| Security | 70 | 352 | TOTP nach RFC 6238 (Fenster, Replay-Schutz über totp_last_counter), Base32, Login-Sperre, 2FA-Challenge und Recovery-Codes, API-Key-Middleware (Bearer, Scopes, IP-Bindung, Ablauf, Widerruf, Rate Limit, *503 api_keys_disabled bei Flag false*), Audit-Hash-Kette (append-only, Verifikation, Anker, *zwei Logger-Instanzen unter Lock*), Rollen und Gates, Sitzungsverwaltung (*absolute Sitzungsdauer, same_site strict*), *Re-Authentifizierung 2fa.fresh per TOTP oder Passwort* |
| Connector | 54 | 294 | DAV-Multistatus-Parser (eine Implementierung für WebDAV, CardDAV, CalDAV), ConnectorManager, CapabilityRegistry (Hard Locks inkl. *documents.overwrite*), RateLimitManager, CircuitBreaker (*Trial-Timeout, abortTrial*), HttpClientFactory (*BLOCKED_METHODS als Konstante, PUT nur mit If-None-Match innerhalb allowedWritePrefix, DAV-Adapter nur lesende Methoden*), RemoteRequestLogger, Probe, *ConnectorProvenance (webdav, carddav, caldav, file_import, rest_api_slot)* |
| Documents | 32 | 332 | Ordner- und Dokumentspiegel, *Kappung der Auflistung ohne Sweep (Konflikt listing_truncated)*, *zweistufiger Soft Delete (missing, missing_twice, Schutzgrenze mass_missing)*, *404 = degraded folder_missing ohne Sweep*, ETag-Wechsel, Move-Erkennung, Chunking, Posteingang-Upload idempotent, *pending-Anträge mit Inhalt im Blob-Speicher, api_key-Anträge nie ohne approve*, *ExecuteWriteOperationJob, 412 = rejected*, unknown-Auflösung nur per PROPFIND, *ScanDocumentFoldersJob mit Lock und DLQ*, DELETE/MOVE/COPY/MKCOL gesperrt |
| Contacts | 25 | 183 | vCard 2.1/3.0/4.0, Mapper, CardDAV-Pull (*sync-token nie als Cursor*, ETag-Diff, Multiget, sync-collection), *SweepGuard (zwei Fehlen, Ratio- und Absolutgrenze, uncertain_identity)*, Duplikatvorschläge, *HttpDavTransport über HttpClientFactory mit auth_scheme*, Schreibpfad gesperrt |
| Calendar | 8 | 58 | iCalendar-Parser (TZID, ganztägig, DURATION, RRULE, RECURRENCE-ID), CalDAV-Pull über HttpClientFactory, *SweepGuard*, Schreibpfad gesperrt |
| Sync | 45 | 393 | RunSyncJob (*Lock je Connection und Entität für alle Modi, Owner aus Job-UUID, Heartbeat je Chunk, Lock-Verlust = aborted, tries 0 mit maxExceptions*), DLQ (*ProcessDlqRetryJob mit expireAfter und markReplayFailed*), Konflikte, proposed_change, Field-Mapping-Versionen, Payload-Archiv, Stale, Bootstrap (*sync-token in tokens*), Zeitpläne nach 07 Abschnitt 1.5, *Correlation-ID-Reset je Job*, *Heartbeat worker/scheduler (hub:worker:heartbeat, hub:heartbeat:check)* |
| Imports | 42 | 307 | CSV-Reader, Header-Fingerprint, Drop-Ordner und Quarantäne, Stammdaten-Importer (*Sweep nur gleiche Quelle, Connection und Exporttyp, missing_count, Soft Delete erst beim zweiten Fehlen*), *OP-Abgleich nur bei Vollexport derselben Connection*, DATEV-EXTF, CAMT.053, Export-Job (*Neutralisierung von Formelpräfixen*), *Webhook-Ereignisse aus Importen* |
| Api | 30 | 670 | Directory, Pagination (*Cursor-Pagination*) und Provenance (*connector = Adaptername*), Filter, RFC-7807, Scopes, Cases, Kontakt-PATCH 202, *Upload 202 mit operation_uuid und Statusendpunkt, mandantenscoped, kein PUT im Request*, *Idempotency-Marker 409 in_progress*, *Fremdschlüsselprüfung case_id/source_document_id*, *proposals und conflicts lesend*, Health (*Redis-Queue-Tiefe, down bei unerreichbarem Redis, degraded bei altem Worker-Heartbeat*), OpenAPI, vCard-Writer, *Auth und Throttle auf allen api/*-Routen* |
| Webhooks | 13 | 145 | Outbox und Zustellungen, HMAC-Signatur und Replay-Fenster, Retry-Plan und DLQ, Endpunkt-API mit Scope, *WebhookUrlGuard über App\Core\Support\UrlGuard (https, keine privaten oder lokalen Ziele, DNS-Rebinding, keine Redirects)*, *queued_at gegen Doppelzustellung*, *hub:webhooks:emit-stale* |
| Core | 78 | 226 | BootGuard (*shouldRun: false nur in local/testing*), Migrationen auf SQLite, *MariaDB-Trigger-Migration (Referenz-SQL konsistent)*, Formatter, Checksummen, SecretMasker, `hub:doctor`, *UrlGuard, WritePrefixGuard, HashedIdentifier (HMAC mit Pepper)*, *TrustedProxies*, *WriteGuard Connection-Scope* |
| EndToEnd | 3 | 49 | WebDAV-Multistatus → RunSyncJob → `GET /api/v1/documents` mit Provenance (connector webdav), Outbox document.created; CardDAV → contacts, directory, vcf; fehlgeschlagener Lauf → SyncRun failed, DLQ, Outbox sync.failed |
| Admin | 102 | 870 | Alle Admin-Bereiche, *Records mit Rechten records.view/payloads.view und Audit*, *Nutzerverwaltung (assertAssignable vor Passwortänderung, Sitzungen beenden, letzter Owner)*, *Connections (SSRF-Prüfung base_url, allowed_write_prefix Pflicht bei write, paired_read_connection_id Pflicht und mandantenscoped, Reset der Schreibfreigabe bei Scope-Änderung)*, *Webhook-Formular mit SafeWebhookUrl*, 2FA-Pflicht ohne Ausnahme für read_only |
| Mcp | 14 | 207 | MCP-Tool-Katalog (12 Tools), Tool-Aufrufe über die API-Schicht, Scopes |
| Contract (Testsuite) | 38 | 137 | Mock-Immoware-Server, elf Fehlerszenarien, DavServerContractTest (7 Skips ohne `CONTRACT_DAV_BASE_URL`) |

Nicht durch automatisierte Tests abgedeckt: Verhalten des echten Immoware24-DAV-Servers (Abschnitt 3.2), MariaDB-spezifisches Verhalten inkl. Trigger (Tests laufen auf SQLite, MariaDB-Zweig nur im CI-Job tests-mariadb), Redis-Locks und Redis-Queue im Mehrprozessbetrieb (Health-Test prüft nur den Fehlerpfad ohne Server), Mailversand, Docker-Build, Container-Healthchecks und nginx-Konfiguration (kein Daemon in der Entwicklungsumgebung). Diese Punkte sind Bestandteil der Phasen 0 und 9 am eigenen Mandanten.

### 6.1 Ergänzung Mail-Modul (12.09.2026, Integrationslauf)

Lauf `php artisan test` auf Branch claude/vibrant-lovelace-c624qw nach Verdrahtung der Mail-Module: **787 Tests, 5.773 Assertions, 779 bestanden, 8 übersprungen, 0 fehlgeschlagen**, Laufzeit rund 31 Sekunden. Die 554 Bestandstests des Hubs sind unverändert enthalten und grün (Abnahmefall 20). Hinzugekommen sind 233 Tests der Module Mail, Gmail, Cases, Sla, Actions, Lexware, Ai, Drive, MailUi und MailIntegration einschließlich der End-to-End-Tests unter `tests/Feature/MailEndToEnd/` (Push zu Teilerfolg mit Antwortentwurf, IBAN-Fall mit Identitätsprüfung und zwei Freigebenden). `vendor/bin/pint --test` auf allen Anwendungs-, Test-, Config- und Routenpfaden sauber, `phpstan analyse --no-progress` (Level 5) ohne Fehler, `php artisan migrate:fresh --env=testing` auf SQLite vollständig (43 Migrationen, alle Mail-Migrationen additiv), `php artisan route:list` ohne doppelte Methode-Pfad-Kombinationen oder Routennamen (199 Routen, davon 56 domaingebunden auf mail.muellerhv.de), `php artisan schedule:list` zeigt die sechs Mail-Zeitpläne aus `routes/console.php`, `php artisan hub:doctor` zeigt Mail-Flags, Integrationsstatus, Queues und Bereitschaft.

Kein Live-Test gegen Gmail, Lexware Office, Google Drive oder OpenAI: Zugangsdaten fehlen, die Hosts sind in dieser Umgebung gesperrt. Alle HTTP-Aufrufe der Mail-Module laufen in Tests über `Http::fake` oder die Fakes `FakeGmailProvider`, `FakeLexwareApi`, `FakeAiProvider`; Pub/Sub-Tokens werden mit einem eigenen RSA-Schlüsselpaar signiert. Ein grüner Lauf belegt das Verhalten des Hubs gegen angenommene Antwortformate aus Snippets, nicht die Fremd-APIs; ein Mock-Erfolg gilt nie als Live-Test. Status je Abnahmefall 1 bis 20: `docs/mail/06-testplan.md`.

### 6.2 Abschlusslauf Mail-Modul (13.09.2026, nach Security-Review und Fix-Lauf)

Lauf `php artisan test` auf Branch claude/vibrant-lovelace-c624qw nach dem Fix-Lauf zu den 60 Findings des Security-Reviews (`docs/mail/08-datenschutz-sicherheit.md` Abschnitt 11): **844 Tests, 6.115 Assertions, 836 bestanden, 8 übersprungen, 0 fehlgeschlagen**, Laufzeit rund 33 Sekunden. Davon 282 Tests der Mail-Module (Mail, Gmail, Cases, Sla, Actions, Lexware, Ai, Drive, MailUi, MailIntegration, MailEndToEnd) mit 1.892 Assertions; die 554 Bestandstests des Hubs sind unverändert grün. `vendor/bin/pint --test` auf dem gesamten Repository sauber, `phpstan analyse --no-progress` (Level 5) ohne Fehler, `php artisan view:cache` ohne Fehler, `php artisan migrate:fresh --env=testing` auf SQLite vollständig (43 Migrationen, neu `2026_09_13_110001_mail_restrict_cascade_deletes`, No-op auf SQLite), `php artisan route:list` 200 Routen, davon 57 domaingebunden auf mail.muellerhv.de (neu: Entwurfsfreigabe), `hub:openapi:export` und `hub:mcp:export` regeneriert. `routes/console.php` enthält acht Mail-Zeitpläne (neu: `mail-actions-outbox` minütlich, `mail-actions-recheck-manual` stündlich); `schedule:list` scheitert in dieser Umgebung nur am fehlenden Redis. Kein Live-Test gegen Gmail, Lexware Office, Google Drive oder OpenAI, alle Fremdsysteme als Fakes. Offene Punkte nach dem Fix-Lauf: 08 Abschnitt 11.2.

## 7. Review 12.09.2026

Interner Code-Review des Gesamtstands gegen CLAUDE.md und die Konzeptdokumente (01, 02, 05, 07, 08, 09): **85 Findings gemeldet, 57 bestätigt** (5 kritisch, 25 hoch, 27 mittel), 28 verworfen. Die Behebung erfolgte im Fix-Lauf 12.09.2026 parallel in vier Bereichen (A: Connector, Contacts, Calendar, Documents, Sync; B: Imports, Api, Webhooks, Mcp; C: Security, Admin, Core; D: Dokumentation und Betrieb) mit anschließendem Abschlusslauf (Schnittstellen zwischen den Bereichen, Gesamtprüfung). Regel des Fix-Laufs: Jeder Fix erhält einen Test, der den Fehler zuvor reproduziert; Tests laufen je Bereich per `--filter`, Pint nur auf eigene Pfade, PHPStan komplett.

| Schwere | Anzahl | Schwerpunkte |
|---|---|---|
| kritisch | 5 | Endlosschleife bei sync-token-Cursor (RunSyncJob, BootstrapService), Mark-and-Sweep der CSV-Importer zu breit, Doppelverarbeitung durch Queue-Konfiguration (retry_after), Regelverstoß Schreibpfad (pending-Anträge ohne Fortsetzung) |
| hoch | 25 | Rechteprüfung und Audit in Records, 2FA-Ausnahme read_only, TRUSTED_PROXIES, Idempotenz-Race, SSRF Webhooks, Feature-Flag API-Keys, Rechteausweitung Passwortänderung, Soft Delete beim ersten Fehlen (Dokumente, Kontakte, Kalender), Audit-Hash-Kette unter Last, Status unknown ohne Auflösung, Scheduler systemd, Circuit Breaker, Locks ohne TTL, Datenbank-Trigger fehlten |
| mittel | 27 | Session-Härtung, Re-Authentifizierung, Scope-Prüfung Fremdschlüssel, Rate Limit Webhooks, CSV-Injection, Doku-Code-Widersprüche (Statusmaschine, Capability-Schlüssel, Endpunkte, connector_type), Healthchecks, Deploy-Kriterium, Correlation-ID im Worker |

Dokumentationsfolgen (Bereich D, erledigt 12.09.2026): 02-data-model.md Abschnitt Abgleich mit dem Code, 05-write-capabilities.md Statusmaschine mit WriteOperationStatus, 08-security.md sechs Rollen und Abschnitt 11, 09-api-documentation.md Ist-Stand der Endpunkte mit Kennzeichnung geplant, docs/operations (Scheduler als Dauerdienst, Healthchecks, Queue-Tiefe Redis, TRUSTED_PROXIES), docs/architecture/03-mariadb-triggers.sql mit Migration 2026_09_12_130000 (Test tests/Feature/Core/MariaDbTriggersMigrationTest.php, MariaDB-Zweig nur im CI-Job tests-mariadb).

### 7.1 Status je Finding (Abschlusslauf 12.09.2026)

Ergebnis: 52 behoben, 2 behoben, Teil abgelehnt, 3 Dokument angepasst. Kein Finding vollständig abgelehnt. Bei "Dokument angepasst" wurde nach Datenintegrität entschieden und das Dokument mit Änderungsvermerk 12.09.2026 an den Code angepasst.

| Nr. | Schwere | Datei | Kategorie | Status | Umsetzung |
|---|---|---|---|---|---|
| 1 | hoch | `app/Modules/Admin/Http/Controllers/RecordsController.php` | Fehlende Rechteprüfung / Audit | behoben | Rechte records.view und payloads.view, Audit admin.records.viewed und payload_viewed |
| 2 | hoch | `app/Modules/Security/Http/Middleware/RequireTwoFactor.php` | 2FA-Umgehung nach Rolle | behoben | exempt_roles leer, Tests auf 2FA-Pflicht für alle Rollen umgestellt |
| 3 | hoch | `bootstrap/app.php` | Fehlende Proxy-Konfiguration | behoben | trustProxies mit TRUSTED_PROXIES (Default leer, * verworfen) |
| 4 | hoch | `app/Modules/Api/Http/Middleware/IdempotencyMiddleware.php` | Idempotenz-Race | behoben | atomarer In-Progress-Marker vor dem Controller, 409 idempotency_in_progress |
| 5 | hoch | `app/Modules/Webhooks/Jobs/DeliverWebhookJob.php` | SSRF | behoben | WebhookUrlGuard über UrlGuard, Regel SafeWebhookUrl in API- und Admin-Request, Prüfung vor jedem Versand, keine Redirects |
| 6 | hoch | `app/Modules/Security/Http/Middleware/AuthenticateApiKey.php` | Nicht durchgesetztes Feature-Flag | behoben, Teil abgelehnt | 503 api_keys_disabled statt 403; Sperre in ApiKeyService::create bewusst nicht umgesetzt (Anlage vor Freischaltung betrieblich sinnvoll, Flag auf Systemseite sichtbar) |
| 7 | hoch | `app/Modules/Admin/Http/Controllers/UsersController.php` | Rechteausweitung | behoben | assertAssignable vor Passwortänderung, Sitzungen des Zielnutzers beendet, Audit |
| 8 | mittel | `app/Modules/Security/Models/AuditLog.php` | Integrität Audit-Kette | behoben | Cache-Lock audit:chain plus lockForUpdate plus Unique-Index prev_hash |
| 9 | mittel | `app/Modules/Admin/Http/Requests/ConnectionRequest.php` | SSRF über Basis-URL | behoben | App\Core\Support\UrlGuard plus Allowlist HUB_CONNECTION_ALLOWED_HOSTS in AllowedConnectionHost |
| 10 | mittel | `app/Modules/Security/Services/Totp.php` | TOTP-Replay | behoben | totp_last_counter war bereits umgesetzt, Tests vorhanden |
| 11 | mittel | `config/session.php` | Sitzungsschutz | behoben | same_site strict, secure außer local, absolute Dauer 480 Min., EnforceAbsoluteSessionLifetime in bootstrap/app.php |
| 12 | mittel | `routes/modules/security.php` | Fehlende Re-Authentifizierung | behoben | Middleware 2fa.fresh (15 Min.) mit Bestätigung per TOTP oder Passwort auf allen sicherheitskritischen Routen |
| 13 | mittel | `app/Modules/Api/Http/Controllers/DocumentUploadController.php` | Fehlende Scope-Prüfung Fremdschlüssel | behoben | exists-Regeln mit organization_id in UploadDocumentRequest, 422 |
| 14 | mittel | `routes/modules/webhooks.php` | Rate-Limit-Lücke | behoben | Throttle war vorhanden, Test sichert api.auth und api.throttle auf allen api/*-Routen |
| 15 | mittel | `app/Modules/Imports/Jobs/ExportJob.php` | CSV-Injection | behoben | Neutralisierung war vorhanden, Test ergänzt |
| 16 | kritisch | `app/Modules/Contacts/Services/DavPullRunner.php` | Endlosschleife / Cursor-Semantik | behoben | cursor immer null, sync-token in SyncResult::tokens und CollectionStateStore |
| 17 | kritisch | `app/Modules/Imports/Services/Importers/AbstractCsvImporter.php` | Mark-and-Sweep löscht zu früh und zu breit | behoben | Sweep nur gleiche Quelle, Connection, Exporttyp und gesehene Objekte; missing_count, Soft Delete beim zweiten Fehlen |
| 18 | hoch | `app/Modules/Imports/Services/Importers/OpenItemsImporter.php` | Snapshot-Abgleich ohne Scope | behoben, Teil abgelehnt | settled_at nur bei Vollexport derselben Connection und gesehener Objekte; Snapshot je (external_id, as_of_date) abgelehnt, eine Zeile je Posten mit jüngstem Stichtag (02 Nr. 11) |
| 19 | hoch | `app/Modules/Documents/Services/DocumentMirrorService.php` | Abgeschnittene Auflistung führt zu Soft Delete | behoben | Kappung setzt truncated, kein Sweep, Konflikt listing_truncated |
| 20 | hoch | `app/Modules/Documents/Services/DocumentMirrorService.php` | Soft Delete beim ersten Fehlen, Schutzgrenze ohne Wirkung | behoben | zweistufig missing_since, Soft Delete erst im Folgelauf bei health_ok, Schutzgrenze mass_missing |
| 21 | hoch | `app/Modules/Contacts/Services/ContactMirrorService.php` | Sweep ohne Schutzgrenze, Ein-Lauf-Löschung | behoben | SweepGuard mit required_misses 2, Ratio- und Absolutgrenze, uncertain_identity |
| 22 | hoch | `app/Modules/Security/Models/AuditLog.php` | Race Condition Hash-Kette | behoben | siehe Nr. 8, Test mit zwei Logger-Instanzen |
| 23 | hoch | `app/Modules/Documents/Jobs/ResolveUnknownWriteOperationJob.php` | Status unknown wird nie aufgelöst | behoben | Dispatch verifiziert, Overlap-Schutz immoware:write:{id} mit expireAfter |
| 24 | hoch | `app/Modules/Documents/Services/PosteingangUploadService.php` | Nicht fortsetzbare pending-Anträge | behoben | Inhalt im Blob-Speicher, bei Ablagefehler rejected content_storage_failed, Fortsetzung über approve und hub:write:resume |
| 25 | hoch | `app/Modules/Api/Http/Middleware/IdempotencyMiddleware.php` | Idempotenz check-then-insert | behoben | siehe Nr. 4 |
| 26 | mittel | `app/Modules/Sync/Jobs/RunSyncJob.php` | Lock-Freigabe bei transientem Fehler | behoben | Lock je Connection und Entität, Owner aus Job-UUID, kein Release im catch, Retry nimmt Lock auf |
| 27 | mittel | `app/Modules/Documents/Connectors/WebDavConnector.php` | Fremder SyncRun wird überschrieben | behoben | runId aus SyncRequest, eigener Run nur ohne runId |
| 28 | mittel | `app/Modules/Documents/Jobs/ScanDocumentFoldersJob.php` | Paralleler Scan ohne Lock | behoben | SyncLockManager-Lock, Lock-Owner in Chunk-Kette, DLQ in failed() |
| 29 | mittel | `app/Modules/Webhooks/Jobs/DeliverWebhookJob.php` | Doppelzustellung | behoben | WithoutOverlapping vorhanden, queued_at gegen Doppelzustellung |
| 30 | mittel | `app/Modules/Documents/Services/DocumentMirrorService.php` | 404 auf Collection löscht Teilbaum | behoben | 404 = degraded folder_missing plus Konflikt, kein Sweep |
| 31 | mittel | `app/Core/Database/BlueprintMacros.php` | Unique-Ebene abweichend vom Datenmodell | Dokument angepasst | organisationsweiter Unique als strengere Regel beibehalten, 02-data-model.md Nr. 3; Connection-Wechsel als Konflikt duplicate_external offen |
| 32 | kritisch | `app/Modules/Documents/Services/PosteingangUploadService.php` | Regelverstoß Schreibpfad / Doku-Code-Widerspruch | behoben | PosteingangUploadService::submit() plus ExecuteWriteOperationJob (Queue write), API-Controller nutzt submit(), 202 mit operation_uuid, kein PUT im Request |
| 33 | hoch | `database/migrations/2026_09_12_000101_create_connector_tables.php` | Doku-Code-Widerspruch Sperrebenen | behoben | Migration 2026_09_12_130000 mit MariaDB-Triggern (audit_logs, write_operations), Referenz-SQL, übrige Prüfungen als Anwendungslogik gekennzeichnet |
| 34 | hoch | `app/Modules/Documents/Services/PosteingangUploadService.php` | Vier-Augen-Prinzip nicht geprüft | behoben | writeApprovalIncompleteReason() in Service und WriteGuard |
| 35 | hoch | `app/Modules/Admin/Http/Controllers/ConnectionsController.php` | Aushebelung Schreibbegrenzung | behoben | resetWriteApproval() bei Änderung von purpose, connector_type, base_url, allowed_write_prefix; Audit connections.write_approval_reset |
| 36 | hoch | `config/hub/core.php` | Flag hebelt hard_locked-Sperre aus | behoben | BootGuard::shouldRun, false nur in local/testing, sonst Warnung und Guard läuft; 05 Abschnitt 2.1 |
| 37 | hoch | `app/Modules/Connector/Http/HttpClientFactory.php` | Methoden-Guard konfigurierbar und unvollständig | behoben | BLOCKED_METHODS Konstante inkl. POST, PATCH; PUT nur mit If-None-Match: * innerhalb allowedWritePrefix; DAV-Adapter nur lesende Methoden |
| 38 | mittel | `app/Modules/Contacts/Dav/HttpDavTransport.php` | Nicht belegte Annahme im Code | behoben | HttpDavTransport über HttpClientFactory, auth_scheme der Connection |
| 39 | mittel | `app/Modules/Connector/Enums/CapabilityKey.php` | Doku-Code-Widerspruch Capability-Schlüssel | Dokument angepasst | 05 Abschnitt 2.2 mit Zuordnung Konzeptname zu CapabilityKey, documents.overwrite (hard_locked) ergänzt |
| 40 | mittel | `docs/immoware/09-api-documentation.md` | Doku beschreibt nicht existierende Endpunkte | behoben | 09 Abschnitte 3 bis 5 auf Ist-Stand mit Spalte Stand (umgesetzt/geplant), neue Endpunkte proposals, conflicts, documents/uploads/{uuid} |
| 41 | mittel | `app/Core/Enums/WriteOperationStatus.php` | Doku-Code-Widerspruch Statusmaschine | Dokument angepasst | 05, 09 und 02 auf Enum-Werte pending, prechecked, sent, unknown, verified, failed, rejected mit Konzeptnamen-Spalte; Code belassen |
| 42 | mittel | `app/Modules/Documents/Services/PosteingangUploadService.php` | Datenintegrität Spiegel | behoben | denialReason paired_read_connection_missing ohne Rückfall; Admin-Formular: Pflichtfeld bei purpose write, nur Lese-Connections webdav_documents des eigenen Mandanten |
| 43 | mittel | `app/Modules/Admin/Http/Controllers/ConnectionsController.php` | Nicht dokumentierter connector_type | behoben | 02-data-model.md Nr. 6: connector_type mit sechs Werten, purpose write nur WebDAV, Guards prüfen es |
| 44 | kritisch | `app/Modules/Contacts/Services/DavPullRunner.php` | Endlosschleife Chunk-Re-Dispatch | behoben | siehe Nr. 16 |
| 45 | kritisch | `config/queue.php` | Doppelverarbeitung durch Queue-Konfiguration | behoben | REDIS_QUEUE_RETRY_AFTER Default 3600, .env.example |
| 46 | hoch | `app/Modules/Sync/Jobs/RunSyncJob.php` | Fehlerbehandlung failed() trifft fremden Lauf | behoben | tries 0, maxExceptions aus hub.sync.jobs.tries, Release zählt nicht |
| 47 | hoch | `deploy/systemd/immoware-hub-scheduler.service` | Scheduler-Konfiguration systemd | behoben | immoware-hub-scheduler.service als schedule:work Dauerdienst, Timer entfernt |
| 48 | hoch | `app/Modules/Connector/Services/CircuitBreaker.php` | Circuit Breaker Half-Open ohne Ausweg | behoben | trial_started_at mit trial_timeout_seconds, abortTrial(), jeder Status beendet den Trial |
| 49 | hoch | `app/Modules/Sync/Jobs/RunSyncJob.php` | Locks ohne Heartbeat, Lock-Leak, Abweichung 07-sync-strategy.md Abschnitt 5 | behoben | Lock für alle Modi, Heartbeat je Chunk, Lock-Verlust = aborted, TTL max(config, 2x Timeout, 1800) |
| 50 | hoch | `app/Modules/Sync/Jobs/ProcessDlqRetryJob.php` | Lock ohne TTL, DLQ-Retry hängt | behoben | expireAfter(timeout+60), failed() setzt DlqService::markReplayFailed |
| 51 | mittel | `app/Modules/Api/Health/HealthService.php` | Health-Endpunkt liefert falsches Ergebnis | behoben | Redis-Queue: PING, LLEN je Queue, ZCARD delayed/reserved, Verbindungsfehler = down; Worker-Heartbeat-Alter in details, degraded ab 180 s (Abschlusslauf) |
| 52 | mittel | `compose.yaml` | Docker-Healthcheck ohne Aussagekraft | behoben | hub:worker:heartbeat (minütlich, Scheduler direkt, Worker über Queue high) und hub:heartbeat:check als Healthcheck in compose.yaml (Abschlusslauf) |
| 53 | mittel | `app/Providers/AppServiceProvider.php` | Logging ohne korrekte Correlation-ID im Worker | behoben | SyncServiceProvider::registerCorrelationReset (Queue::before/after/failing) |
| 54 | mittel | `app/Modules/Webhooks/Console/RedeliverWebhooksCommand.php` | Doppelte Webhook-Zustellung | behoben | queued_at, Redeliver nur failed, fällig und nicht eingereiht |
| 55 | mittel | `app/Modules/Sync/Jobs/RunSyncJob.php` | Doppelte Fehlerbehandlung | behoben | fail()/notifyFailure nur in failed() bzw. einmalig im synchronen Pfad |
| 56 | mittel | `deploy/scripts/deploy.sh` | Deploy-Prüfung an falschem Signal | behoben | deploy.sh prüft /health/database und /health/queue, gibt Antwortkörper bei Fehlschlag aus |
| 57 | mittel | `app/Modules/Documents/Jobs/ScanDocumentFoldersJob.php` | Job ohne Overlap-Schutz und ohne DLQ | behoben | siehe Nr. 28, tries 0 / maxExceptions 5 |

### 7.2 Im Abschlusslauf gelöste Schnittstellen zwischen den Bereichen

- Api und Documents: `DocumentUploadController` ruft `PosteingangUploadService::submit()`; Antwort 202 mit `outcome`, `queued`, `approval_required`, `status_url`; Test sichert, dass der Request kein PUT auslöst.
- Webhooks und Core: `WebhookUrlGuard` ist eine Fassade über `App\Core\Support\UrlGuard` (eine Regelmenge, Container-Resolver aus `tests/TestCase.php` greift auch für Webhooks); Admin-`StoreWebhookEndpointRequest` nutzt `SafeWebhookUrl`.
- Api und Connector: `Provenance::adapterName()` delegiert an `ConnectorProvenance::connectorName()`.
- Security und Factories: `ImmowareConnectionFactory` setzt `base_url_hash` nicht mehr selbst (saving-Hook mit `HashedIdentifier`).
- Admin und Documents: `paired_read_connection_id` im Connection-Formular (Pflicht bei purpose write, mandantenscoped, nicht die Connection selbst).
- Betrieb und Sync: Heartbeat-Kommandos und Healthchecks (Nr. 52), Redis-Prüfung in `/health/queue` (Nr. 51); `.gitignore` um `.env.testing` ergänzt.

### 7.3 Verbleibende offene Punkte

1. Contacts und Calendar: Wechsel der Connection bei gleicher externer ID wird still übernommen; laut 02-data-model.md Nr. 3 als Konflikt `duplicate_external` zu behandeln (nicht umgesetzt).
2. Getrennter Tageslauf 03:00 nur für den Ordner Dokumente und wöchentliche Probe je Connection (07 Abschnitt 1.5) sind nicht im Scheduler (Kommentar in `config/hub/sync.php`).
3. MariaDB-Trigger lokal nicht ausführbar (kein Server); Verifikation nur über CI-Job tests-mariadb, zu verifizieren.
4. `invoice.created` ohne Auslöser, bis ein Rechnungsimport existiert.
5. Webhook-Ereignisse für Upload-Statuswechsel (09 Abschnitt 5.3) bleiben geplant.
6. Kein Contract-Test Dokumentation gegen OpenAPI; verbindlich ist die generierte `docs/api/openapi.json`.
7. Alle Aussagen zum Verhalten des echten Immoware24-DAV-Servers bleiben VERMUTET bzw. zu verifizieren (Phase 0).

Unverändert: Kein Test lief gegen den echten Immoware24-Mandanten. Alle Zugangswege bleiben VERMUTET bzw. zu verifizieren, der Schreibpfad bleibt gesperrt (README Abschnitt Status).
