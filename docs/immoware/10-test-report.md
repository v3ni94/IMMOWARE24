# 10 Testprotokoll Immoware Hub

Stand: 11.09.2026
Gesellschaft: Hausverwaltung Müller GmbH (Mandant Immoware24)
Status: Vorlage. Es wurden noch keine Tests am eigenen Mandanten durchgeführt.

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

## 3. Mock-Server-Plan tests/mock-immoware

Zweck: reproduzierbare Tests aller Adapter, Retry-Stufen, Breaker und Guards ohne Zugriff auf den Mandanten. Der Mock bildet ausschließlich das nach, was als VERIFIZIERT oder DOKUMENTIERT gilt, plus konfigurierbare Fehlerfälle. Er ist keine Aussage über das tatsächliche Verhalten des Immoware24-DAV-Servers; alle Annahmen im Mock sind als solche im Code kommentiert und in Phase 0 gegen die Probe abzugleichen.

### 3.1 Aufbau

```
tests/mock-immoware/
  docker-compose.yml        Container mit WebDAV-, CardDAV- und CalDAV-Endpunkt
  config/
    scenarios/*.yaml        Szenarien: Statuscodes, Latenzen, Feature-Flags
    fixtures/
      dms/Posteingang/      Beispiel-PDFs (synthetisch, keine Echtdaten)
      dms/Dokumente/
      contacts/*.vcf        synthetische vCards, keine Daten aus dem Bestand
      calendar/*.ics
      csv/                  synthetische Exporte in mehreren Header-Varianten
  src/                      Mock-Implementierung (PHP oder Node, Entscheidung offen)
  README.md
```

Datenschutz: Fixtures enthalten ausschließlich synthetische Daten. Keine Kontakte, Objekte oder Dokumente der Hausverwaltung Müller GmbH werden in den Mock kopiert.

### 3.2 Feature-Flags (aus Probe-Ergebnis abzuleiten)

| Flag | Default im Mock | Bezug |
|---|---|---|
| auth_scheme | basic | Auth-Schema am Mandanten VERMUTET, Digest ebenfalls testbar |
| etag_stable | true und false | ETag-Stabilität NICHT VERFÜGBAR |
| sync_token | false | RFC 6578 NICHT VERFÜGBAR |
| ctag | false | NICHT VERFÜGBAR |
| if_none_match | true und false | NICHT VERFÜGBAR |
| folders_exposed | Posteingang, Dokumente | VERIFIZIERT als mindestens vorhanden, weitere VERMUTET |
| addressbooks | 1 und 3 | Aufteilung nach Kontakttypen VERMUTET |
| rate_limit_rps | unbegrenzt und 2 | Limits NICHT VERFÜGBAR |

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

### 3.4 Szenarien (config/scenarios)

| Datei | Inhalt |
|---|---|
| happy_path.yaml | alle Endpunkte 200, etag_stable true, if_none_match true |
| no_features.yaml | kein sync-token, kein CTag, ETags instabil, Strategie muss auf lastmodified_size_hash fallen |
| auth_failure.yaml | 401 ab Request 3 |
| rate_limited.yaml | jeder fünfte Request 429 mit Retry-After 10 |
| flaky_5xx.yaml | 20 Prozent 500, Breaker muss öffnen |
| write_conflicts.yaml | Zielpfad existiert, 412 und 409 Fälle |
| drop_after_put.yaml | Verbindungsabbruch nach PUT-Body |
| mass_missing.yaml | 30 Prozent der Ressourcen fehlen plötzlich, Schutzgrenze muss greifen |
| fingerprint_change.yaml | Server-Header ändert sich zwischen zwei Proben |
| csv_variants.yaml | drei Header-Varianten, BOM, Windows-1252, Semikolon und Komma |

### 3.5 Abnahmekriterien Mock

- Alle Szenarien laufen in der CI (GitHub Actions) bei jedem Pull Request.
- Kein Szenario darf ein DELETE, MOVE, COPY, PROPPATCH, LOCK, UNLOCK oder ein PUT ohne If-None-Match vom Hub empfangen. Der Mock protokolliert jede eingehende Methode; ein Verstoß lässt den Test fehlschlagen.
- put_attempts überschreitet in keinem Szenario den Wert 1 je write_operation.
- Nach Abschluss jedes Szenarios ist der Spiegel per hub:replay --from payload aus external_payloads identisch rekonstruierbar (Checksum-Vergleich).

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
| Technologie des Mock-Servers (PHP oder Node) | offen | Entscheidung vor Sprint 1 |
