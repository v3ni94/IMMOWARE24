# Immoware Hub, finale Architekturentscheidung

Stand: 11.09.2026
Gesellschaft: Hausverwaltung Müller GmbH (Auftraggeber und Betreiber des Immoware24-Mandanten)
Dokumentstatus: Entwurf, Freigabe durch die Geschäftsführung ausstehend

Auftraggeber: Hausverwaltung Müller GmbH. Zielsystem: Laravel 12, PHP 8.4, MariaDB 10.11+, Redis 7, Horizon-Queues, Scheduler. Immoware24 bleibt führendes System. Der Hub ist ein nachvollziehbarer, versionierter Spiegel mit genau einem eng begrenzten Schreibpfad.

Basis ist der Entwurf integrity-first. Aus mvp-first wurden die Probe vor Sprint 1, die Persistierung der Sync-Strategie je Connection, der Degraded-Status, die stale-Kennzeichnung, die Exit-Strategie und die MVP-Streichliste übernommen. Aus extensibility-first wurden proposed_change als Rückweg, merged_into_id statt physischem Merge, das Backup der Hub-eigenen Entscheidungen, der Ordner-Fingerprint, die Sync-Alter-Anzeige und die Erinnerung bei überfälligen Exporten übernommen. Die Public REST-API, der MCP-Layer, Telefonie-Lookup und Drittkonsumenten aus extensibility-first werden nicht gebaut, bevor der Schreibpfad produktiv und stabil ist.

## Belegregeln für dieses Dokument

Jede Aussage zu Immoware24 trägt einen der folgenden Status aus der Schnittstellenrecherche (Stand 11.09.2026):

| Status | Bedeutung |
|---|---|
| VERIFIZIERT | Offizielle Immoware24-Quelle (immoware24.de, support.immoware24.de, content.immoware24.de, config.dav.immoware24.de), Wortlaut in einem WebSearch-Snippet oder per Fetch tatsächlich gesehen. Ein nahezu wörtlich gesehener Kernsatz zählt, eine sinngemäße Zusammenfassung nicht. |
| DOKUMENTIERT | Quelle mit URL, die die Aussage trägt: offizielle Quelle, deren Wortlaut nur einmal gesehen, in der Gegenprüfung nicht reproduziert oder nur als Snippet-Paraphrase vorliegt, oder glaubwürdige Drittquelle. |
| VERMUTET | Plausibel, aber nicht durch gesichtete Quelle gedeckt, oder Interpretation über den Quellentext hinaus. Vor Nutzung am eigenen Mandanten zu verifizieren. |
| NICHT VERFÜGBAR | Negativbefund: kein Beleg gefunden. Keine offizielle Aussage, dass die Funktion fehlt. Gilt auch für alle Aussagen der Form "ohne API", "nicht dokumentiert", "keine Angabe". |

Diese Definition ist für alle Dokumente des Repositories verbindlich (Leitdefinition in README.md). Abweichende Kurzfassungen in Einzeldokumenten sind durch diese Tabelle ersetzt.

Hinweis zur Prüfumgebung: Die Hosts www.immoware24.de, support.immoware24.de, content.immoware24.de und config.dav.immoware24.de waren aus der Rechercheumgebung per Direktabruf gesperrt (EGRESS_BLOCKED). Belege stützen sich auf Such-Snippets. Zitate, die nur als Snippet-Paraphrase vorliegen, gelten bis zur Sichtung des Originals als vorläufig und sind in Phase 0 am eigenen Mandanten oder im Original zu bestätigen. Offene Punkte, die nur der Anbieter klären kann, sind mit WAITING_FOR_VENDOR_ACCESS gekennzeichnet.

## 0. Belegstand und Zugangswege

Es wird kein Endpunkt, kein Pfadschema und kein Auth-Verfahren angenommen, das nicht belegt ist.

| Zugangsweg | Status | Lesen | Schreiben | Konsequenz für den Hub |
|---|---|---|---|---|
| WebDAV auf DMS, mindestens Ordner Posteingang und Dokumente | VERIFIZIERT (Existenz, Netzlaufwerk, Scanner-Upload, Änderungen und Löschungen wirken im Livesystem; Quelle: support.immoware24.de Artikel 360010764437, 360010770277, Anleitung_DAV-Adapter.pdf © 2023) | ja | Upload belegt, Overwrite und Delete wirken sofort | Einziger geplanter Schreibkanal, ausschließlich create-only in den Posteingang |
| CardDAV (Kontaktfreigabe) | VERIFIZIERT für Existenz (Anleitung_DAV-Adapter.pdf, Artikel 360010764417), Schreibrichtung unklar, Aussage "nur lesend" nur VERMUTET | plausibel ja | nicht eingeplant | Lesequelle für Kontakte, hash-basiert |
| CalDAV (Kalenderfreigabe) | wie CardDAV, Android-Hinweis "Schreibschutz erzwingen" (Artikel 360010887358, VERMUTET, Wortlaut nicht gesichtet) | plausibel ja | nicht eingeplant | Lesequelle für Termine, niedrige Priorität |
| Konfigurationsportal config.dav.immoware24.de | VERIFIZIERT (Web-UI "Dav-Adapter Login Page", Freigaben durch Benutzer admin unter Verwaltung, eigenes Freigabe-Passwort je Nutzer, Freigaben nicht editierbar, nur löschen und neu anlegen; Pfad /share nur VERMUTET) | UI | UI | Kein programmatischer Zugriff. Technischer Nutzer und Freigaben werden manuell angelegt |
| DAV-Modul | VERIFIZIERT als buchbares Zusatzmodul, Aktivierung über Support oder Vertrieb (Artikel 360010876038, Wortlaut "buchen"); Kostenpflicht ist Ableitung (VERMUTET) | | | Buchung ist Blocker vor Phase 0, Kosten klären (WAITING_FOR_VENDOR_ACCESS) |
| CSV-Export der Auswertungen (Mieter- und VE-Stammdaten, Kontakte, OP-Listen) | DOKUMENTIERT (Export-Button, manuell; Artikel 360018128817, 360018097757), Spaltenformat NICHT VERFÜGBAR | ja | nein | Datei-Import über Drop-Ordner oder Upload in der Hub-UI, Format erst nach Sichtung echter Dateien fixieren |
| DATEV-CSV-Buchungsexport | DOKUMENTIERT (manuell, Kontenmapping, Festschreibung wählbar, nur Miet- und WEG-Verwaltung, KOST2; immoware24.de/funktionen/datev) | ja | nein | Datei-Import, Phase 3 |
| SEPA pain.001/pain.008, CAMT.053 v02/v08, MT940 STA | DOKUMENTIERT (Erzeugung bzw. Import über UI und Banking-Client; Artikel 4406428565009, 28896190561053, 30222978488477) | Datei | Datei | Hub liest CAMT.053 nur zur Anzeige, kein Einspielen in Immoware24 |
| HeiWaKo/bved DTA (B/K, L/M, D, E898) | DOKUMENTIERT (Artikel 16615479078173, 6109432110109, 6108954097309) | Datei | Datei | Optional, Phase 3, nur lesend |
| OpenImmo (FTP-Ziel des Portals) | VERMUTET (Drittquelle wohnglueck.de) | nein | Immoware24 sendet | Nicht Teil des Hubs |
| E-Post, Portal24, craftware24, KI-Anrufbeantworter, Ticketsystem | DOKUMENTIERT als UI-Funktionen; Fremd-API NICHT VERFÜGBAR (Negativbefund) | UI | UI | Nicht anbindbar. Vorgänge im Hub verweisen nur textuell auf Ticketnummern |
| REST-API, Webhooks, API-Keys, Zapier/Make/n8n | NICHT VERFÜGBAR (Negativbefund, keine offizielle Aussage; Packagist, npm und GitHub ohne Client-Bibliothek, Stand 11.09.2026) | nein | nein | Kein Baustein darf darauf bauen. Adapter-Slot in der Registry bleibt vorgesehen |
| Rate Limits, Quotas, SLA, Sitzungs-Timeouts | NICHT VERFÜGBAR | | | Feste konservative Limits, Backoff, Lastprofil in sync_runs nachweisbar |
| AGB zu automatisiertem Zugriff | DOKUMENTIERT nur allgemein (Server nicht schädigen, Zugangsdaten schützen), Wortlaut nicht geprüft | | | Schriftliche Bestätigung des Immoware24-Supports vor Produktivbetrieb (WAITING_FOR_VENDOR_ACCESS), Prüfung durch Rechtsanwalt |
| DMS-Papierkorb | DOKUMENTIERT: automatische Löschung nach 7 Tagen (AGB, Snippet) | | | Fehluploads sind nach 7 Tagen nicht mehr aus dem Papierkorb wiederherstellbar |
| Nutzerrollen | DOKUMENTIERT: SYS: Administrator, SYS: Standard, SYS: nur Lesezugriff, SYS: nur Lesezugriff Stammdaten (Artikel 360010771138) | | | Welche Rolle DAV-Freigaben tragen darf, ist NICHT belegt und wird in Phase 0 getestet (zu verifizieren am eigenen Mandanten) |

## 1. Leitprinzipien

1. Immoware24 ist Master. Der Hub hält einen versionierten Spiegel und niemals die einzige Kopie einer fachlichen Wahrheit. Hub-eigene Daten (Konfliktentscheidungen, Merges, Zuordnungen, API-Keys) sind die Ausnahme und werden gesondert gesichert.
2. Rohdaten vor Interpretation. Jede Nutzlast wird unverändert mit SHA-256 in external_payloads archiviert, bevor ein Mapper sie anfasst. Mapping ist eine reine Funktion und jederzeit aus dem Archiv reproduzierbar.
3. Hash statt Zeitstempel. Änderungserkennung stützt sich primär auf inhaltliche Hashes normalisierter Daten, sekundär auf sync-token, CTag, ETag und getlastmodified. Kein Zugangsweg liefert ein verlässliches updated_at.
4. Schreiben ist die Ausnahme. Genau eine Schreiboperation existiert: neue Datei per WebDAV in den Posteingang legen. Kein PUT auf bestehende Ressourcen, kein DELETE, MOVE, COPY, PROPPATCH, LOCK, kein Schreiben über CardDAV oder CalDAV, kein Schreiben in den Ordner Dokumente.
5. Idempotenz und Nachvollziehbarkeit. Jeder Schreibversuch wird vor Ausführung als write_operation persistiert, mit Idempotenzschlüssel versehen und nach Ausführung verifiziert. Der Zustand unknown wird ausschließlich lesend aufgelöst. Auditlog ist append-only mit Hash-Kette.
6. Read-only ist Standard. Jede immoware_connection startet mit write_enabled = false. Aktivierung erfordert zwei Personen (Rolle release, Vier-Augen-Prinzip) und die protokollierte Freigabe der Geschäftsführung. Das gilt auch für die erstmalige Aktivierung.
7. Fähigkeiten werden nicht angenommen, sondern gemessen. Was der Immoware24-DAV-Server kann, stellt eine Probe fest und wird je Connection persistiert. Was nicht gemessen wurde, gilt als nicht vorhanden.
8. Datenalter ist sichtbar. Jede Ausgabe des Hubs trägt data_age_seconds, stale_since und den Belegstatus der Quelle, damit Konsumenten manuelle Exportrhythmen erkennen.

## 2. Systemüberblick

```
+-------------------------------------------------------------------------+
| Immoware Hub (Laravel Monolith, modular, ein Deployment)                |
|                                                                         |
|  Modules/                                                               |
|   Core        Auth (2FA TOTP), Rollen, API-Keys (Argon2id + Scopes),    |
|               Audit (append-only, Hash-Kette)                           |
|   Connectors  ImmowareConnectorInterface + Adapter                      |
|               WebDavDocumentConnector   lesen, create-only Posteingang  |
|               CardDavContactConnector   lesen                           |
|               CalDavCalendarConnector   lesen                           |
|               CsvExportConnector        Datei-Import Auswertungen       |
|               DatevExportConnector      Datei-Import Buchungen (Ph. 3)  |
|               BankFileConnector         CAMT.053 lesen (Ph. 3)          |
|   Capability  CapabilityRegistry (Belegstatus, Test, Hard Lock)         |
|   Probe       ServerProbe (Strategie je Connection, Fingerprint)        |
|   Sync        SyncOrchestrator, ChangeDetector, Mapper, Reconciler,     |
|               Bootstrap (Erstimport)                                    |
|   Domain      properties, units, contacts, contracts, documents ...     |
|   Writes      WriteOperationService (Idempotenz, Precheck, Verify)     |
|   Conflicts   ConflictQueue inkl. proposed_change (Human-in-the-Loop)   |
|   Resilience  feste Limits je Host, CircuitBreaker, DLQ, Degraded-Mode  |
|   Outbound    HMAC-Webhooks (Modul vorhanden, erst mit Konsument aktiv) |
+-------------------------------------------------------------------------+
        |  Redis: Queues (Horizon), Locks, Breaker-State
        |  MariaDB: Spiegel, Versionen, Payload-Archiv, Audit
        |  Blob-Speicher (S3-kompatibel oder Dateisystem, EU-Standort):
        |  Payloads > 64 KB, CSV-, DATEV-, CAMT-Dateien, on-demand Dokumente
        v
 Immoware24 (Master)  <- WebDAV / CardDAV / CalDAV, eigener technischer
                         Nutzer, separates Freigabe-Passwort, Freigaben
                         durch admin nach Buchung des DAV-Moduls
 Manuelle Exporte     -> Drop-Ordner (SFTP auf Hub-Server) oder Upload
                         in der Hub-UI mit Pflichtmetadaten
```

Technische Nutzer: Ein dedizierter Immoware24-Nutzer der Hausverwaltung Müller GmbH mit der kleinsten Rolle, die DAV-Freigaben trägt (in Phase 0 zu ermitteln, nicht belegt). Für den Schreibpfad wird ein zweiter technischer Nutzer angelegt, dessen Dateifreigabe nur den Posteingang umfasst, sofern der DAV-Adapter eine Beschränkung auf Ordner zulässt (VERMUTET, zu verifizieren am eigenen Mandanten). Ist das nicht möglich, bleibt die Trennung organisatorisch, und der Schutz liegt vollständig bei den Code-Guards aus Abschnitt 6. Diese Abhängigkeit ist im Freigabeprotokoll der Geschäftsführung ausdrücklich zu benennen.

## 3. Connector-Schicht

### 3.1 Interface

```php
interface ImmowareConnectorInterface
{
    public function capabilities(ConnectionContext $ctx): CapabilitySet;   // aus Registry plus Probe, nie hart kodiert
    public function probe(ConnectionContext $ctx): ProbeResult;            // stellt Serverfähigkeiten fest
    public function healthCheck(ConnectionContext $ctx): HealthResult;
    public function listChanges(ConnectionContext $ctx, ?SyncCursor $cursor): ChangeBatch;
    public function fetch(ConnectionContext $ctx, ExternalRef $ref): RawPayload;
    public function write(ConnectionContext $ctx, WriteIntent $intent): WriteResult; // wirft CapabilityDenied
}
```

ChangeBatch enthält je Ressource external_id, remote_etag, remote_ctag_or_token, remote_last_modified, remote_size und den nächsten SyncCursor (opaque JSON). Kein Adapter liefert fachliche Objekte, nur RawPayload plus Metadaten. Mapping erfolgt in der Sync-Schicht.

### 3.2 Probe und Sync-Strategie (übernommen aus mvp-first)

Vor dem ersten produktiven Lauf und danach wöchentlich führt jede Connection eine Probe aus:

1. OPTIONS und PROPFIND Depth 0 auf den Freigabe-Root: DAV-Header, supported-report-set, sync-token, calendarserver-getctag, Auth-Challenge (Basic oder Digest), Server-Header. Das Auth-Schema des DAV-Endpunkts ist NICHT belegt (VERMUTET) und wird hier gemessen.
2. ETag-Stabilitätsprüfung: zwei PROPFIND Depth 1 auf dieselbe Collection im Abstand von 60 Sekunden ohne zwischenzeitliche Änderung. Nur wenn alle ETags identisch sind, wird ETag als Erkennungsmerkmal zugelassen.
3. If-None-Match-Prüfung im Posteingang mit einer Testdatei, die anschließend manuell durch einen berechtigten Mitarbeiter im DMS entfernt wird (kein DELETE durch den Hub). Antwort 412 beim zweiten PUT gilt als Unterstützung.
4. Ergebnis wird als Server-Fingerprint (Hash über DAV-Header, Report-Set, Server-Header, Auth-Schema) in immoware_connections.server_fingerprint gespeichert und die Strategie in sync_states.strategy je Collection festgelegt: sync_token, ctag_etag, etag_only, lastmodified_size_hash oder full_hash.
5. Ändert sich der Fingerprint zwischen zwei Proben, wechselt die Connection in den Status degraded: Lesen läuft weiter, alle Uploads pausieren automatisch, Betrieb wird benachrichtigt. Freigabe zurück auf active erfolgt manuell nach Prüfung.

### 3.3 Capability Registry

Tabelle capabilities mit Schlüsseln webdav.list, webdav.read, webdav.create, webdav.overwrite, webdav.delete, webdav.move, carddav.read, carddav.write, caldav.read, caldav.write, csv.import.units, csv.import.contacts, csv.import.open_items, datev.import, camt.import. Jede Capability hat evidence_status, source_url, enabled, hard_locked, tested_at, tested_by, test_protocol.

Regeln:
- enabled darf nur true sein, wenn evidence_status in (VERIFIZIERT, DOKUMENTIERT), tested_at gesetzt und hard_locked = 0. Die Prüfung erfolgt in der Anwendung und zusätzlich als Datenbank-Trigger.
- webdav.overwrite, webdav.delete, webdav.move, carddav.write, caldav.write sind hard_locked = 1 und zusätzlich im HTTP-Client durch einen Methoden-Guard gesperrt, der DELETE, MOVE, COPY, PROPPATCH, LOCK, UNLOCK und PUT ohne If-None-Match: * unabhängig von jeder Konfiguration mit Exception abbricht. Das ist bewusst doppelt.
- Fähigkeiten unterhalb DOKUMENTIERT werden in keiner Hub-Ausgabe als verfügbar gemeldet.

### 3.4 Externe Identität je Zugangsweg

| Quelle | external_id | Stabilität | Zweitschlüssel |
|---|---|---|---|
| WebDAV Datei | Pfad relativ zur Freigabe, UTF-8, NFC-normalisiert | mittel, Umbenennen oder Verschieben erzeugt neue ID | content_hash, sofern bekannt, zur Move-Erkennung |
| CardDAV Kontakt | vCard UID, sonst href | UID vermutlich stabil (VERMUTET) | similarity_hash nur zur Duplikatswarnung, nie zur Zusammenführung |
| CalDAV Termin | iCalendar UID plus RECURRENCE-ID | wie CardDAV | href |
| CSV Auswertung | Kombination aus Objektnummer, VE-Nummer bzw. Vertragsnummer laut key_schema des import_formats | unbekannt bis Exportdatei gesichtet (NICHT VERFÜGBAR) | row_hash; ohne bestätigtes key_schema gilt identity_confidence = uncertain |
| DATEV-Zeile | row_hash über Belegfeld 1, Buchungsdatum, Konto, Gegenkonto, Betrag, Text plus occurrence_no (Vorkommenszähler identischer row_hash innerhalb der Datei) | nicht garantiert eindeutig | Duplikate werden bewusst als Duplikat gespeichert, nicht verschmolzen; occurrence_no verhindert den Verlust der zweiten identischen Zeile am Unique-Schlüssel |
| CAMT.053 Umsatz | AcctSvcrRef bzw. EndToEndId plus Betrag und Buchungsdatum | meist stabil | row_hash |

Der Hub vergibt eigene UUIDv7 als Primärschlüssel. Die Zuordnung zu externen Schlüsseln liegt ausschließlich in external_mappings.

## 4. Änderungserkennung ohne updated_at

### 4.1 WebDAV (Dokumente)

Standardintervalle: Posteingang alle 30 Minuten, Dokumente täglich, Full Reconcile wöchentlich nachts. Intervalle werden erst nach Sichtung des Lastprofils verkürzt.

1. PROPFIND Depth 1 auf den Freigabe-Root, dann je Ordner gemäß document_folders.scan_interval_seconds. Concurrency 2, feste Pause 500 ms zwischen Requests. Properties: getetag, getlastmodified, getcontentlength, resourcetype, getcontenttype.
2. Ordner-Fingerprint (übernommen aus extensibility-first, aber nur als Beschleuniger, nie als alleinige Wahrheit): Hash über die sortierte Kind-Liste mit Name, Größe, lastmodified, ETag. Unveränderter Fingerprint erlaubt, den Teilbaum in diesem Lauf zu überspringen. Der wöchentliche Full Reconcile ignoriert Fingerprints und prüft jede Collection, weil WebDAV-Collections tiefe Änderungen nicht garantiert nach oben melden.
3. Vergleich je Ressource mit sync_states nach der persistierten Strategie. Unverändert nur, wenn alle verfügbaren Merkmale identisch sind. Bei Abweichung oder fehlendem ETag wird die Datei nur dann heruntergeladen und gehasht, wenn der Ordner content_policy = hash_on_change oder store trägt. Im Standard (metadata_only) wird eine Metadatenversion angelegt, detected_by = lastmodified oder etag, und content_hash bleibt null.
4. Neue Version nur bei tatsächlicher Änderung des vorliegenden Wahrheitsmerkmals: content_hash, falls vorhanden, sonst Kombination aus Größe und lastmodified. ETag-Wechsel ohne Änderung dieser Merkmale wird als unchanged_meta_noise protokolliert.
5. Move-Erkennung (korrigiert gegenüber Erstentwurf): Fehlt eine Ressource und taucht im selben Lauf eine neue Ressource mit identischer Größe und identischem lastmodified auf, wird der Kandidat heruntergeladen und gehasht, sofern für die alte Ressource ein content_hash vorliegt. Stimmt der Hash, gilt es als Verschiebung (external_id aktualisiert, Historie erhalten). Liegt kein alter Hash vor, wird die Verschiebung als moved_probable in die Konfliktqueue gelegt und erst nach zwei Läufen als Löschung plus Neuanlage verarbeitet. Für den Posteingang und andere Ordner mit content_policy hash_on_change ist die Erkennung damit vollständig, für metadata_only Ordner nur wahrscheinlichkeitsbasiert.
6. Soft Delete im Spiegel erst, wenn die Ressource in zwei aufeinanderfolgenden Läufen fehlt, beide Läufe mit erfolgreichem Health-Check abgeschlossen wurden und der Ordner selbst erreichbar war. Beim ersten Fehlen nur missing_since. Das gilt auch beim Full Reconcile.
7. Dateiinhalte werden nur gespeichert, wenn content_policy = store. Standard ist Referenz, nicht Kopie.

sync-collection (RFC 6578) wird nur genutzt, wenn die Probe es festgestellt hat.

### 4.2 CardDAV (Kontakte) und CalDAV (Termine)

1. Discovery über die vom Nutzer kopierte Freigabe-URL aus dem Konfigurationsportal. Keine Well-Known-Discovery voraussetzen. Die konkreten Server-URLs sind öffentlich nicht belegt (VERMUTET) und werden aus dem eigenen Mandanten übernommen.
2. Je Lauf PROPFIND Depth 0 auf die Collection: sync-token und CTag. Strategie laut sync_states.strategy: sync-collection REPORT mit letztem Token, sonst CTag-Vergleich mit anschließendem Depth-1-ETag-Vergleich, sonst voller Depth-1-Vergleich.
3. Geänderte Einträge per addressbook-multiget bzw. calendar-multiget in Paketen von 50 hrefs über die Queue, feste Rate 2 Requests pro Sekunde. Bei 4.600 Kontakten initial 92 Requests, bei 250.000 Kontakten 5.000 Requests, was bei dieser Rate rund 42 Minuten dauert und akzeptabel ist.
4. Normalisierung der vCard (Zeilenenden, Property-Reihenfolge, Entfernen von REV, PRODID, X-Properties ohne Fachbezug) und SHA-256. Nur Hash-Änderung ist ein Update. REV wird als Hinweis gespeichert, nicht als Wahrheit.
5. Mehrere Adressbücher (Kontakttypen laut Snippet, VERMUTET) werden je als eigene Collection geführt. Liefern zwei Freigaben denselben Kontakt mit derselben UID, entsteht ein external_mapping je Collection auf denselben Hub-Kontakt; bei abweichender UID entsteht ein Duplikatsvorschlag, kein Merge.
6. Ungültiges Token (403 mit valid-sync-token Fehler oder 410): Cursor verwerfen, voller Depth-1-Vergleich, Vorfall im Auditlog.

### 4.3 CSV-Exporte, DATEV- und CAMT-Dateien

1. Eingang über Drop-Ordner (SFTP, je Exporttyp ein Unterordner) oder Upload in der Hub-UI. Pflichtmetadaten: Exporttyp, Objekt oder objektübergreifend, Exportdatum, exportierender Mitarbeiter, Kennzeichen Vollexport oder Teilexport. Datei wird vollständig in external_payloads archiviert.
2. Datei-Hash identisch zu einem früheren Import: Abweisung als Duplikat.
3. Header-Fingerprint gegen import_formats. Unbekannter Header führt zu Quarantäne (Status quarantined, Konflikttyp format_mismatch) und Stopp für diesen Exporttyp. Kein Raten. Ein Operator bestätigt oder legt eine neue Formatversion mit column_mapping und key_schema an.
4. Zeilenweise row_hash über normalisierte Zellen. Nur neue oder geänderte Zeilen erzeugen Versionen. Zeilen, die in einem als Vollexport gekennzeichneten Import für genau dieses Objekt fehlen, erhalten missing_since; Soft Delete erst nach dem zweiten Vollexport ohne Treffer (Snapshot-Semantik). Teilexporte löschen nie.
5. Zeichensatz (UTF-8 mit und ohne BOM, Windows-1252) und Trennzeichen werden erkannt, protokolliert und gegen import_formats validiert.
6. Export-Erinnerung: Je Exporttyp ist eine verantwortliche Person und ein Sollrhythmus hinterlegt (export_schedules). Überfällige Exporte erzeugen eine Erinnerung an die Person und setzen stale_since auf allen abhängigen Datensätzen.

### 4.4 Bootstrap-Verfahren für den Erstimport

Der Erstimport von 67 Objekten, 869 Einheiten und rund 4.600 Kontakten (Bestand Hausverwaltung Müller GmbH, Stichtag 01.07.2026) darf die Konfliktqueue nicht fluten. Ablauf:

1. Phase 0 liefert je Exporttyp eine echte Datei. Aus ihr wird das key_schema abgeleitet und als import_formats Version 1 mit Status draft angelegt.
2. Bootstrap-Lauf im Modus dry_run: alle Zeilen werden gemappt, external_mappings werden nur vorgeschlagen, ein Bericht zeigt Schlüsselkollisionen, leere Schlüssel und Ähnlichkeitsgruppen.
3. Operator bestätigt das key_schema. Danach Bootstrap-Lauf im Modus accept_all_exact: alle Zeilen mit eindeutigem Schlüssel werden ohne Konflikteintrag als identity_confidence = exact angelegt. Nur Kollisionen und leere Schlüssel landen in der Konfliktqueue.
4. CardDAV-Kontakte werden im Bootstrap zu CSV-Kontaktzeilen nur dann automatisch verknüpft, wenn E-Mail, Nachname und Vorname exakt übereinstimmen und genau ein Kandidat existiert; die Verknüpfung ist ein external_mapping der CSV-Connection auf den CardDAV-Kontakt (created_by = bootstrap, im Bericht einzeln sichtbar). Jede contacts-Zeile behält genau eine Quelle; die CardDAV-Zeile ist für Kontaktfelder führend, CSV-Felder werden nicht in sie geschrieben, Abweichungen erzeugen den Konflikttyp source_mismatch (data-ownership.md, contacts). Alle anderen Fälle sind Vorschläge. Nach dem Bootstrap ist dieser Auto-Link deaktiviert.
5. Der Bootstrap-Bericht wird als Testprotokoll im Repository abgelegt.

### 4.5 Generisch

Jede Änderungserkennung endet in einem sync_event mit detected_by, old_checksum, new_checksum, payload_id. Damit ist jede Version auf ihre Erkennungsgrundlage zurückführbar.

## 5. Sync-Orchestrierung

- Scheduler startet je Connection und Adapter einen SyncRun mit Redis-Lock (kein paralleler Lauf derselben Quelle).
- Phasen: discover, fetch, archive, map, reconcile, finalize. Cursor wird erst in finalize committet.
- Mapping ist eine reine Funktion RawPayload zu DomainDTO ohne Datenbankzugriff. Der Reconciler entscheidet create, update, unchanged, moved, conflict.
- Konflikt liegt vor bei unsicherer Identität, doppeltem externem Schlüssel, lokal geschützter Annotation gegen entfernte Änderung, Formatabweichung, fehlgeschlagener Schreibverifikation oder existierendem Schreibziel. Konflikte blockieren nur den betroffenen Datensatz.
- proposed_change (übernommen aus extensibility-first): Möchte ein Hub-Nutzer oder ein angeschlossenes System einen Kontakt, Termin oder Stammdatensatz in Immoware24 ändern, wird kein Writeback ausgeführt. Stattdessen entsteht ein Eintrag vom Typ proposed_change mit Ziel, Feld, altem und neuem Wert und Begründung. Ein Mitarbeiter setzt die Änderung manuell in Immoware24 um und schließt den Eintrag; der nächste Sync bestätigt die Umsetzung über den Hash. Das ist der einzige Rückweg für alle nicht belegten Schreibrichtungen.
- Resilienz: feste Limits je Host (2 Requests pro Sekunde, Concurrency 2 lesend, 1 schreibend), exponentielles Backoff mit Jitter bei 429, 503, Timeouts. CircuitBreaker je Connection (öffnet nach 5 Fehlern in 2 Minuten, Half-Open nach 10 Minuten). Fehlgeschlagene Jobs nach 5 Versuchen in dlq_items, manuelle Wiederaufnahme. Ein Token-Bucket-Manager wird nicht gebaut; die Limits sind Konfiguration, kein Subsystem.
- stale-Kennzeichnung: Ist ein Health-Check fehlgeschlagen oder ein Export überfällig, tragen alle Datensätze der Connection stale_since. Jede Hub-Antwort liefert data_age_seconds und source_status (fresh, stale, degraded).

## 6. Schreibpfad (einziger): Dokument in den Posteingang

Fachlicher Zweck: vom Hub erzeugte oder aus Mailflüssen und Scans stammende Dokumente in den Immoware24-Posteingang legen, wo sie laut Support-Artikel durch OCR- und KI-Erkennung weiterverarbeitet werden (DOKUMENTIERT, Quelle: support.immoware24.de Artikel 5556851032733, Snippet; die Toshiba-Seite nennt nur "Weiterverarbeitung" und ist selbst DOKUMENTIERT; Originaltexte im Repository ablegen). Ob Immoware24 hochgeladene Dateien automatisch Objekten zuordnet, ist NICHT belegt; der Hub liefert nur eine Dateinamenskonvention.

Ablauf:

1. WriteIntent wird als write_operations-Zeile mit status = queued und idempotency_key = SHA-256(connection_id, content_hash, intent_key) angelegt; intent_key ist der Idempotency-Key des API-Aufrufers bzw. der fachliche Bezug (source_document_id, case_id) des UI- oder System-Antrags (Details 05-write-capabilities.md Abschnitt 3.1). Der Zielpfad mit UUIDv7-Suffix wird erst nach der Schlüsselprüfung vergeben und ist nicht Teil des Schlüssels. Existiert der Schlüssel mit status in (queued, precheck, sent, unknown, verifying, succeeded, skipped_exists), wird ohne Netzwerkzugriff das bestehende Ergebnis zurückgegeben.
2. Guards: Capability webdav.create enabled, connection.write_enabled, connection.status = active (nicht degraded), Zielpfad beginnt mit allowed_write_prefix, Dateigröße unter Limit (Default 25 MB, keine Immoware24-Angabe belegt), Dateiname sanitisiert (ASCII, keine Pfadtrenner, keine führenden Punkte, maximal 120 Zeichen) und mit Hub-UUIDv7-Suffix versehen, damit Kollisionen mit Scanner-Uploads ausgeschlossen sind.
3. Precheck: PROPFIND Depth 0 auf den Zielpfad. Existiert die Ressource, status = skipped_exists und Konflikteintrag write_target_exists. Overwrite ist damit auch dann ausgeschlossen, wenn der Server If-None-Match ignoriert.
4. PUT mit If-None-Match: *. Antwort 412 wird wie skipped_exists behandelt. Vor dem Senden wird status = sent persistiert.
5. Verifikation: PROPFIND auf den Zielpfad, Vergleich getcontentlength, danach GET und SHA-256-Vergleich (Standard an, abschaltbar nur je Connection mit Begründung im Audit). Erst dann status = succeeded. Bei Abweichung status = failed_verify, Konflikteintrag, kein automatischer Retry, weil ein zweites PUT die Regel kein Overwrite verletzen würde.
6. Netzwerkfehler nach PUT ohne verwertbare Antwort: status = unknown. Der einzige zulässige Folgeschritt ist PROPFIND, niemals ein erneutes PUT. Findet PROPFIND die Ressource, geht es mit Schritt 5 weiter; findet es sie nach drei Prüfungen im Abstand von je 5 Minuten nicht, status = failed und manuelle Entscheidung.
7. Neustart des Hubs: alle write_operations in status sent oder unknown werden ausschließlich über PROPFIND weitergeführt.
8. Jede Operation erzeugt einen Auditeintrag und, sobald ein Konsument registriert ist, einen ausgehenden Webhook.

Freigabevoraussetzungen vor Aktivierung (Geschäftsführung, protokolliert): schriftliche Bestätigung des Immoware24-Supports, dass ein automatisierter WebDAV-Upload durch eine Serveranwendung zulässig ist (WAITING_FOR_VENDOR_ACCESS); Buchung des DAV-Moduls; eigener technischer Nutzer; erfolgreiche Probe inklusive If-None-Match; Testlauf im Posteingang mit Testdokumenten und Protokoll; Prüfung der AGB durch einen Rechtsanwalt.

## 7. Sicherheit, Datenschutz, Betrieb

- Hub-Login mit Pflicht-2FA (TOTP, Wiederherstellungscodes). Rollen: viewer, operator (Importe, Konflikte, proposed_change), admin (Connections, Capabilities, Formate), release (zweite Person für write_enabled und für das Zurücksetzen von degraded auf active).
- API-Keys für spätere Fremdsysteme: Argon2id-Hash, Prefix, Scopes, Ablaufdatum, letzte Nutzung, Widerruf. In Phase 1 werden keine Keys ausgegeben.
- Immoware24-Zugangsdaten in verschlüsselten Spalten mit dediziertem Schlüssel außerhalb der Anwendungs-ENV (KMS oder HashiCorp Vault). Rotationsprozess dokumentiert, weil das Freigabe-Passwort für alle Freigaben des Nutzers gilt (VERIFIZIERT, "Das Passwort sollte von Ihrem Immoware24-Passwort abweichen und gilt für alle Ihre Freigaben.", Anleitung zum DAV-Adapter bzw. Artikel 360010768217; die Reset-Folge aus Artikel 360010887358 ist DOKUMENTIERT, Quellenzuordnung in 04-authentication.md Abschnitt 2.3). Health-Check erkennt 401 und öffnet den Breaker. Ein App-Passwort-Konzept mit mehreren widerrufbaren Tokens ist NICHT VERFÜGBAR; das Freigabe-Passwort ist als geteiltes Geheimnis je Nutzer zu behandeln.
- Ausgehende Webhooks: HMAC-SHA256 über Body plus Zeitstempel, Replay-Fenster 5 Minuten, Outbox-Tabelle gegen Doppelzustellung, Retry mit Backoff. Modul ist vorhanden, wird aber erst aktiviert, wenn ein konkreter Konsument benannt ist.
- Auditlog: append-only, Anwendungs-DB-Nutzer ohne UPDATE und DELETE, Hash-Kette je Zeile, wöchentlicher Export der Kettenwurzel an einen unveränderlichen Speicher (Object Lock). Täglicher Export wird erst ab Phase 2 aktiviert.
- DSGVO: Kontaktdaten nur im Umfang der freigegebenen Adressbücher. Löschkonzept: Soft Delete im Hub, Hard Delete personenbezogener Felder nach konfigurierter Frist (Default 12 Monate nach Ende der letzten Rolle, mit Steuerberater und Rechtsanwalt abstimmen), external_payloads mit contains_personal_data werden nach Ablauf pseudonymisiert (Inhalt gelöscht, Hash bleibt, Replay dieses Datensatzes ist dann nicht mehr möglich und wird im Audit vermerkt). Verzeichnis der Verarbeitungstätigkeiten, AV-Vertrag mit dem Hoster, Prüfung des AV-Vertrags mit Immoware24 durch Rechtsanwalt. Hosting und Blob-Speicher ausschließlich in der EU, bevorzugt Deutschland (Immoware24 selbst: Rechenzentren in Deutschland, DOKUMENTIERT, immoware24.de/funktionen/sicherheit).
- Backups: MariaDB täglich voll, Binlog kontinuierlich, Blobs versioniert. Zusätzlich (übernommen aus extensibility-first) ein separates, täglich exportiertes Backup der Hub-eigenen, nicht aus Payloads rekonstruierbaren Daten: conflicts mit Entscheidungen, contact_merges, external_mappings mit created_by manual oder bootstrap, import_formats, capabilities, users, api_keys, webhook_endpoints. Wiederherstellungstest quartalsweise, getrennt für Vollrestore und Replay-Restore.
- Exit-Strategie als Betriebsprozess (übernommen aus mvp-first): unabhängig vom Hub werden monatlich DATEV-Export, CSV-Auswertungen aller Objekte und ein Abgleich der Dokumentliste per WebDAV gesichert, weil eine Exportregelung bei Vertragsende in den AGB laut Snippet nicht belegt ist (VERMUTET, zu verifizieren, Prüfung durch Rechtsanwalt).

## 8. Recovery

| Szenario | Vorgehen |
|---|---|
| Sync-Cursor korrupt oder Token ungültig | Cursor verwerfen, voller Depth-1-Vergleich; Hash-Basis verhindert Dubletten |
| Hub-Datenbank verloren | Restore aus Backup, Full Reconcile; Domain-Tabellen sind zusätzlich per hub:replay --from payload aus external_payloads neu berechenbar; Hub-eigene Entscheidungen kommen aus dem separaten Backup |
| Mapper-Fehler entdeckt | Mapper korrigieren, Replay aus Payloads, neue sync_version je Datensatz, alte Versionen bleiben |
| Fehlerhafter Upload in den Posteingang | Kein automatisches Löschen. Konflikteintrag, manuelle Bereinigung im DMS durch berechtigten Mitarbeiter innerhalb von 7 Tagen (Papierkorb), Dokumentation im Audit |
| Immoware24 ändert Ordnerstruktur, DAV-Server oder CSV-Format | Server-Fingerprint, Header-Fingerprint und Pfad-Allowlist schlagen an, Connection geht auf degraded, Uploads pausieren, Lesen läuft weiter; Format oder Strategie wird versioniert nachgezogen, release schaltet zurück |
| DAV-Passwort zurückgesetzt | 401 im Health-Check, Breaker öffnet, stale_since gesetzt, Secret rotieren |
| Doppelte Kontakte im Spiegel | Nur Vorschlag über similarity_hash; Zusammenführung ausschließlich manuell als merged_into_id mit Rückgängig-Funktion, nie physisch |
| Hub-Neustart während PUT | write_operations in sent oder unknown werden nur über PROPFIND weitergeführt |
| Ausfall Immoware24 oder DAV-Adapter | Lesen aus dem Spiegel mit stale-Kennzeichnung, Uploads queued, keine Datenänderung |

## 9. Phasenplan

Phase 0 (vor Bau, 2 bis 3 Wochen, Blocker): DAV-Modul buchen und Kosten freigeben, technische Nutzer anlegen, Freigaben durch admin, Probe mit Standard-Client und Hub-Probe-Kommando (Auth-Schema, ETag-Stabilität, sync-token, CTag, If-None-Match, Ordnerumfang, Rolle mit DAV-Berechtigung), je eine reale CSV-Auswertung und DATEV-Datei sichten und als import_formats draft anlegen, Supportanfrage zur Zulässigkeit automatisierten Zugriffs, AGB-Wortlaut und Snippet-Zitate im Original sichern und im Repository ablegen.

Phase 1 (lesend, 5 bis 6 Wochen): Core, Auth, Audit, Capability Registry, Probe, WebDAV-Metadaten-Spiegel, CardDAV-Spiegel, CSV-Import Stammdaten mit Bootstrap, Konfliktqueue inklusive proposed_change, stale- und data_age-Ausgabe, Export-Erinnerungen.

Phase 2 (Schreiben, nur Posteingang, 2 bis 3 Wochen): WriteOperationService, Freigabeprozess, Verifikation, DLQ, Degraded-Mode. Aktivierung nur nach Freigabe der Geschäftsführung.

Phase 3 (Buchhaltung lesend): DATEV-CSV, CAMT.053, OP-Listen, optional HeiWaKo lesend, optional CalDAV.

Phase 4 (nur bei konkretem Bedarf): Webhooks für benannte Konsumenten, lesende API mit Scopes.

Alle Punkte mit Status VERMUTET sind vor der jeweiligen Phase im eigenen Mandanten zu verifizieren und als Testprotokoll im Repository abzulegen.

### Offene Punkte (Stand 11.09.2026)

| Punkt | Kennzeichnung |
|---|---|
| Zulässigkeit automatisierter WebDAV-Nutzung durch eine Serveranwendung laut Immoware24 | WAITING_FOR_VENDOR_ACCESS |
| Kosten und Vertragsbedingungen des DAV-Moduls | WAITING_FOR_VENDOR_ACCESS |
| Existenz einer nicht öffentlichen Partner-API oder Exportschnittstelle | WAITING_FOR_VENDOR_ACCESS |
| Auth-Schema des DAV-Endpunkts (Basic oder Digest), ETag-Stabilität, sync-token, CTag, If-None-Match | zu verifizieren am eigenen Mandanten (Probe) |
| Ordnerumfang per WebDAV (nur Posteingang und Dokumente oder gesamte Objektstruktur) | zu verifizieren am eigenen Mandanten |
| Beschränkbarkeit einer Dateifreigabe auf den Posteingang | zu verifizieren am eigenen Mandanten |
| Kleinste Nutzerrolle mit DAV-Berechtigung | zu verifizieren am eigenen Mandanten |
| Schreibrichtung CardDAV und CalDAV | zu verifizieren am eigenen Mandanten, kein Schreibpfad eingeplant |
| Spaltenformat aller CSV-Exporte und der DATEV-Datei | zu verifizieren am eigenen Mandanten (Phase 0 Sichtung) |
| Automatische Objektzuordnung hochgeladener Dateien im DMS | zu verifizieren am eigenen Mandanten |
| Wortlaut der AGB zu automatisiertem Zugriff und zur Datenherausgabe bei Vertragsende | Original sichern, Prüfung durch Rechtsanwalt |

## 10. Selbstkritik, acht Fragen

1. Was kann schiefgehen?
Der gesamte Lesepfad hängt an einem kostenpflichtigen Zusatzmodul, das Immoware24 jederzeit ändern oder untersagen kann, und an manuellen Exporten, die Mitarbeiter vergessen. Die Server-Fähigkeiten sind nicht dokumentiert; fällt die Probe schlecht aus, laufen alle Quellen im Hash-Fallback mit deutlich mehr Requests. Der Schreibpfad kann trotz Precheck und If-None-Match fehlschlagen, wenn Scanner und Hub gleichzeitig in den Posteingang schreiben; das Suffix mit UUID reduziert, aber beseitigt nicht jedes Zeitfenster. Die Zulässigkeit automatisierter Nutzung ist vertraglich ungeklärt.

2. Wo droht Datenverlust?
Ausschließlich über WebDAV im Livesystem: Löschen und Überschreiben wirken sofort, der Papierkorb wird nach 7 Tagen geleert. Gegenmaßnahmen sind der Methoden-Guard im HTTP-Client, hard_locked Capabilities, Precheck, If-None-Match, getrennter Schreibnutzer mit Posteingang-Freigabe (sofern möglich) und die Regel, dass unknown nie zu einem zweiten PUT führt. Im Hub selbst droht Verlust nur durch Fehlkonfiguration der Vollexport-Kennzeichnung; deshalb Soft Delete erst nach zwei Snapshots.

3. Wo drohen Duplikate?
Bei CSV-Zeilen ohne stabile Kennung, bei vCards ohne oder mit wechselnder UID, bei demselben Kontakt in mehreren Freigaben, bei Mehrfachanlage einer Person in Immoware24 als Mieter, Eigentümer und Dienstleister und bei Moves in Ordnern ohne Hash-Policy. Gegenmaßnahmen: key_schema erst nach Sichtung realer Dateien, Bootstrap mit Kollisionsbericht, kein Auto-Merge, merged_into_id mit Rückgängig, Idempotenzschlüssel für Uploads, hash_on_change für den Posteingang.

4. Welche Annahmen sind unbelegt?
ETag-Stabilität, sync-token- und CTag-Unterstützung, If-None-Match-Verhalten, Auth-Schema, tatsächlicher Ordnerumfang per WebDAV, Beschränkbarkeit einer Freigabe auf den Posteingang, welche Nutzerrolle DAV tragen darf, Aufteilung der Kontaktfreigabe nach Typen, Spaltenformat aller CSV-Exporte, automatische Zuordnung hochgeladener Dateien im DMS, Fehlen einer Exportregelung bei Vertragsende. Alle sind als Phase-0-Testpunkte benannt und dürfen bis dahin keine Bauentscheidung tragen.

5. Was passiert, wenn Immoware24 sich ändert?
Server-Fingerprint, Header-Fingerprint und Pfad-Allowlist erkennen Änderungen beim nächsten Lauf, die Connection geht auf degraded, Uploads pausieren, Lesen läuft mit stale-Kennzeichnung weiter. Das verhindert stille Fehlimporte, aber keine Betriebsunterbrechung. Es gibt keinen Vorankündigungskanal; Release Notes im Support-Center müssen manuell beobachtet werden.

6. Wo bleibt der Hub von manuellen Prozessen abhängig?
Stammdaten, offene Posten und Buchungen kommen nur über Exporte, die ein Mitarbeiter auslöst. Für 869 Einheiten ist ein wöchentlicher Rhythmus tragbar, für 50.000 Einheiten nicht. Export-Erinnerungen, feste Verantwortlichkeit und sichtbares Datenalter mildern das, ersetzen aber keine Schnittstelle, die es heute nicht gibt. Jeder proposed_change erfordert Handarbeit in Immoware24.

7. Was ist unnötig kompliziert und wurde deshalb gestrichen oder verschoben?
Bidirektionaler Sync für Kontakte und Termine, Event-Sourcing-Infrastruktur, Microservices, Volltextindex über Dokumentinhalte, Public REST-API und MCP-Layer vor dem Schreibpfad, Auto-Merge von Kontakten, heuristisches CSV-Matching, Token-Bucket-Manager, täglicher WORM-Export in Phase 1, Webhooks ohne Konsument, Adapter für OpenImmo, E-Post, Portal24 und HeiWaKo in Phase 1.

8. Was würde ich anders machen, wenn Phase 0 anders ausgeht?
Liefert der Server keine stabilen ETags und keinen sync-token, werden die Intervalle verlängert (Posteingang 60 Minuten, Dokumente wöchentlich) statt die Rate zu erhöhen. Ist keine Freigabe auf den Posteingang beschränkbar, wird der Schreibpfad nur mit zusätzlicher Freigabe der Geschäftsführung pro Dokumentklasse aktiviert. Verweigert Immoware24 die automatisierte Nutzung schriftlich, bleibt der Hub ein reiner Datei-Import-Spiegel (CSV, DATEV, CAMT) ohne DAV, und der Schreibpfad entfällt vollständig.

## 11. Verbesserungen gegenüber Erstentwurf

1. Probe vor Sprint 1 mit ETag-Stabilitätsprüfung, If-None-Match-Test und persistierter Strategie je Collection (sync_states.strategy) statt Feststellung im ersten Produktivlauf.
2. Server-Fingerprint und Degraded-Status: Lesen läuft weiter, Uploads pausieren automatisch, Rückschaltung nur durch Rolle release.
3. Move-Erkennung korrigiert: Standardlauf speichert nur Metadaten, deshalb Hash-Vergleich nur bei content_policy hash_on_change oder store, sonst moved_probable in der Konfliktqueue. Der Widerspruch des Erstentwurfs ist aufgelöst.
4. Bootstrap-Verfahren für den Erstimport mit dry_run, Kollisionsbericht und accept_all_exact, damit die Konfliktqueue nicht mit 869 Einheiten und 4.600 Kontakten geflutet wird.
5. proposed_change als dokumentierter Human-in-the-Loop-Rückweg für alle nicht belegten Schreibrichtungen.
6. Merge nur als merged_into_id mit Rückgängig, nie physisch; Auto-Merge vollständig gestrichen.
7. Separates Backup der Hub-eigenen Entscheidungen, die aus Payloads nicht rekonstruierbar sind, mit eigenem Restore-Test.
8. stale_since, data_age_seconds und source_status in jeder Ausgabe; Export-Erinnerungen mit fester Verantwortlichkeit.
9. Unbelegte Rollenaussage entfernt: welche Nutzerrolle DAV-Freigaben tragen darf, ist Testpunkt, nicht Annahme.
10. MVP-Streichliste: feste Limits statt Token-Bucket, Webhooks erst mit Konsument, WORM-Export wöchentlich statt täglich, Startintervalle konservativer.
11. Dateinamen-Sanitizing und Größenlimit explizit spezifiziert; Verifikation nach Upload mit GET plus Hash als Standard.
12. Neustartverhalten für write_operations in sent oder unknown ausschließlich über PROPFIND festgelegt.
13. EU-Standort für Datenbank und Blob-Speicher, Löschfristen und Pseudonymisierung mit Audit-Vermerk konkretisiert.
14. Exit-Strategie als monatlicher Betriebsprozess unabhängig vom Hub.
15. Ordner-Fingerprint als Beschleuniger übernommen, aber durch wöchentlichen Full Reconcile ohne Fingerprint abgesichert.

## Quellen (Auswahl, Status laut Recherche vom 11.09.2026)

- https://content.immoware24.de/content/manual/Anleitung_DAV-Adapter.pdf, "Anleitung zum DAV-Adapter (WebDAV, CalDAV, CardDAV) © 2023 Immoware24 GmbH" (VERIFIZIERT, Existenz; Volltext nicht gesichtet)
- https://support.immoware24.de/hc/de/articles/360010764437-Der-Immoware24-DAV-Adapter (VERIFIZIERT per Snippet)
- https://support.immoware24.de/hc/de/articles/360010876038-Einrichten-der-DAV-Funktionalit%C3%A4ten (VERIFIZIERT per Snippet, buchbares Modul)
- https://support.immoware24.de/hc/de/articles/360010876078-Freigaben-im-DAV-Adapter-einrichten (VERIFIZIERT per Snippet)
- https://support.immoware24.de/hc/de/articles/360010770277-Einrichten-der-Dateifreigabe-unter-Windows-10 (VERIFIZIERT per Snippet)
- https://config.dav.immoware24.de/login (VERIFIZIERT, Seitentitel "Dav-Adapter Login Page")
- https://www.immoware24.de/toshiba/ (DOKUMENTIERT, Zitat aus Erstsichtung, in der Gegenprüfung nicht reproduzierbar)
- https://www.immoware24.de/funktionen/datev/ (DOKUMENTIERT)
- https://support.immoware24.de/hc/de/articles/4406428565009-Kontoumsatzdateien-in-Immoware24-importieren (DOKUMENTIERT)
- https://support.immoware24.de/hc/de/articles/15389245891357-Zwei-Faktor-Authentifizierung-aktivieren (DOKUMENTIERT)
- https://support.immoware24.de/hc/de/articles/360010771138-Immoware24-Nutzerrollen (DOKUMENTIERT)
- https://www.immoware24.de/agb/ (DOKUMENTIERT, Wortlaut nicht geprüft)
- https://www.hausverwaltungschecker.de/immoware24/ (VERMUTET, Drittquelle, nicht gesichtet)
