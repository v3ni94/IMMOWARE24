# Rate Limits, Quotas und Drosselung gegenüber Immoware24

Projekt: Immoware Hub. Stand: 11.09.2026.
Statusbegriffe (VERIFIZIERT, DOKUMENTIERT, VERMUTET, NICHT VERFÜGBAR) wie in 01-interface-discovery.md.

## 1. Ergebnis

Zu Rate Limits, Quotas, Drosselung, SLA oder Sitzungs-Timeouts des DAV-Adapters oder anderer Immoware24-Schnittstellen existiert keine auffindbare Angabe. Status NICHT VERFÜGBAR. Das ist ein Negativbefund aus Suchmaschinen-Snippets (mehrere Suchvarianten deutsch und englisch zu Rate Limit, Quota, API-Key, Developer, Timeout gegen support.immoware24.de und immoware24.de ohne Treffer), keine offizielle Aussage, dass es keine Limits gibt. Direkte Abrufe der Hosts waren gesperrt, siehe 01-interface-discovery.md Abschnitt 9.

Daraus folgt für den Hub:

1. Der Hub setzt eigene, konservative, feste Limits, die deutlich unter dem liegen, was ein einzelner Desktop-Client (Windows-Explorer-Netzlaufwerk, DAVx5, Outlook CalDav Synchronizer) im Normalbetrieb erzeugt. Der DAV-Adapter ist laut offiziellen Anleitungen für genau diese Endgeräte-Clients gedacht (VERIFIZIERT: Replikation auf Geräte, Anleitung zum DAV-Adapter und Support-Artikel). Eine Integrations-API ist NICHT VERFÜGBAR (Negativbefund, keine offizielle Aussage).
2. Die Limits sind Konfiguration je Connection, kein eigenes Subsystem. Ein Token-Bucket-Manager wird nicht gebaut.
3. Serverseitige Signale (429, 503, Retry-After, Timeouts, plötzliche 401 oder 403) werden als Drosselungshinweis behandelt und führen zu Backoff und CircuitBreaker, nie zu Wiederholung mit gleicher Rate.
4. Das tatsächliche Lastprofil wird in sync_runs.counters nachweisbar protokolliert (Requests, Bytes, Dauer, Fehler), damit gegenüber Immoware24 jederzeit belegt werden kann, welche Last der Hub erzeugt hat.
5. Vor Produktivbetrieb wird der Immoware24-Support schriftlich gefragt, ob Nutzungsgrenzen für den DAV-Adapter bestehen (WAITING_FOR_VENDOR_ACCESS). Die Antwort ersetzt die Startwerte, wo sie strenger ist.

## 2. Was belegt ist und was nicht

| Thema | Belegstand | Status |
|---|---|---|
| Rate Limits DAV-Adapter | keine Fundstelle | NICHT VERFÜGBAR |
| Quotas (Requests, Bytes, Dateigröße) für WebDAV | keine Fundstelle | NICHT VERFÜGBAR |
| Sitzungs-Timeouts Web-UI oder DAV | keine Fundstelle (Suchmaschinen-Zusammenfassung ohne Quellzitat) | NICHT VERFÜGBAR |
| SLA, Wartungsfenster | keine Fundstelle | NICHT VERFÜGBAR |
| Größenlimits für Uploads in den Posteingang per WebDAV | keine Fundstelle | NICHT VERFÜGBAR. Einzige belegte Größenangabe im Umfeld: E-Post-Brief "maximal 97 Seiten umfassen und 15 MB groß sein" (DOKUMENTIERT), betrifft aber den E-Post-Versand, nicht WebDAV. |
| Empfehlung zur Begrenzung der Freigaben | "Es wird empfohlen, die Anzahl der zu synchronisierenden Kontakte und Termine durch eine Beschränkung der Freigabe zu limitieren" | VERMUTET (Snippet in Gegenprüfung nicht reproduziert). Deutet auf Lastsensibilität des Adapters hin. |
| AGB: Verbot schädigender Tätigkeiten | "Der Kunde verpflichtet sich, jede Art von Tätigkeit zu unterlassen, die die Server, die Onlinesoftware und das Netzwerk schädigen könnten." | DOKUMENTIERT (Snippet). Übermäßige Last wäre vertraglich riskant. |
| Version des DAV-Adapters | Versionsangabe auf der Login-Seite nur aus einem Suchindex-Snippet, Host gesperrt | VERMUTET, für den Hub ohne Nutzen; Änderungen des Servers sind ohne Vorankündigung möglich und werden über den Server-Fingerprint erkannt. |
| Datenvolumen der Hausverwaltung Müller GmbH | 67 Objekte, 869 Verwaltungseinheiten, rund 4.600 Kontakte (Stichtag 01.07.2026, interne Stammdaten) | intern, nicht Immoware24-bezogen |

## 3. Startwerte (konservativ, je Connection konfigurierbar)

Alle Werte sind Defaults in immoware_connections und werden erst nach Sichtung des Lastprofils aus Phase 1 und nach Antwort des Supports angepasst. Änderungen nur durch Rolle admin, protokolliert im Audit. Leitdokument für Intervalle, Zeitfenster, Retry-Stufen und Breaker ist 07-sync-strategy.md (Abschnitte 1.5 und 6); die folgenden Zeilen wiederholen diese Werte nur zur Übersicht.

| Parameter | Startwert | Spalte bzw. Ort | Begründung |
|---|---|---|---|
| Requests pro Sekunde je Host | 2,00 | rate_limit_rps | Unter dem Niveau eines einzelnen Netzlaufwerk-Clients beim Ordnerwechsel. |
| Feste Pause zwischen Requests derselben Connection | 500 ms | Konfiguration Sync | Ergibt zusammen mit der Concurrency die Obergrenze. |
| Concurrency lesend | 2 | max_concurrency_read | Zwei parallele PROPFIND oder GET höchstens. |
| Concurrency schreibend | 1 | max_concurrency_write | Genau ein PUT gleichzeitig; Idempotenz und Verifikation setzen Serialisierung voraus. |
| Multiget-Paketgröße CardDAV/CalDAV | 50 hrefs | Konfiguration Sync | 4.600 Kontakte initial in 92 Requests; bei 250.000 Kontakten 5.000 Requests, rund 42 Minuten bei 2 rps. |
| Poll-Intervall Posteingang | 30 Minuten | document_folders.scan_interval_seconds | Bis Lastprofil bekannt. |
| Poll-Intervall Ordner Dokumente | 24 Stunden | document_folders.scan_interval_seconds | Statische Bereiche. |
| Full Reconcile DMS, CardDAV, CalDAV | wöchentlich, Nacht von Samstag auf Sonntag, 22:00 bis 05:00 (07-sync-strategy.md Abschnitt 1.5) | Scheduler | Ignoriert Ordner-Fingerprints, prüft jede Collection. |
| Poll-Intervall CardDAV | 60 Minuten | poll_interval_seconds | CTag oder sync-token, falls per Probe festgestellt; sonst Depth-1-Vergleich. |
| Poll-Intervall CalDAV | 4 Stunden | poll_interval_seconds | Niedrige Priorität. |
| Probe | wöchentlich, plus vor dem ersten Lauf | Scheduler | Fingerprint-Vergleich, minimaler Request-Umfang. |
| Health-Check | alle 15 Minuten, ein PROPFIND Depth 0 | Scheduler | Erkennt 401, 403, 5xx früh. |
| Max. Upload-Größe | 25 MB (26.214.400 Byte) | max_upload_bytes | Keine Immoware24-Angabe belegt; Wert orientiert sich am E-Post-Limit von 15 MB plus Reserve, zu verifizieren am eigenen Mandanten. |
| Max. Uploads pro Stunde je Connection | 60 | Konfiguration Writes | Schutz gegen Fehlerschleifen im Schreibpfad; Scanner-Volumen der Hausverwaltung Müller GmbH liegt darunter. |
| HTTP-Timeouts | connect 10 s, read 60 s, Download großer Dateien 300 s | HTTP-Client | Keine Immoware24-Angabe; Werte großzügig, damit langsame Antworten nicht als Fehler zählen. |
| Nachtfenster für Volllast (Full Reconcile, Bootstrap) | 22:00 bis 05:00 Europe/Berlin (7 Stunden, 07-sync-strategy.md Abschnitt 1.5) | Scheduler | Vermeidet Kollision mit Bürobetrieb und Scanner-Uploads. |

Erwartete Last mit diesen Werten (Phase 1, Hausverwaltung Müller GmbH):

- Posteingang-Scan: ein PROPFIND Depth 1, unter 1 Minute, 48 Läufe pro Tag.
- Kontakt-Vollabgleich 4.600 Kontakte: unter 2 Minuten, einmal wöchentlich im Full Reconcile; inkrementell darunter.
- DMS Full Reconcile: abhängig von Ordneranzahl (NICHT VERFÜGBAR, Testpunkt Phase 0); bei 2 rps sind in 7 Stunden maximal 50.400 Requests möglich, was für eine Struktur von 67 Objekten mit Unterordnern nach heutiger Einschätzung ausreicht. Reicht es nicht, wird das Intervall verlängert, nicht die Rate erhöht.

## 4. Drosselungslogik

### 4.1 Reaktion auf Serverantworten

| Antwort | Reaktion des Hubs |
|---|---|
| 200, 201, 204, 207 | normal, Zähler in sync_runs.counters |
| 304, 412 (bei If-None-Match) | normal, fachlich ausgewertet |
| 429 | Retry-After-Header respektieren, sonst Retry-Stufen 30 s, 2 min, 10 min, 30 min mit Jitter, dann dlq_items (07-sync-strategy.md Abschnitt 6.1). Rate der Connection für den laufenden Run halbieren (mindestens 0,25 rps). Zähler throttled_429. |
| 503 | wie 429. |
| 500, 502, 504 | dieselben Retry-Stufen, maximal 4 Wiederholungen je Job, dann dlq_items. |
| Timeout, Verbindungsabbruch | wie 5xx; bei laufendem PUT gilt Sonderregel: status = unknown, ausschließlich PROPFIND als Folgeschritt, niemals erneutes PUT. |
| 401 | kein Retry. CircuitBreaker öffnet sofort, Connection auf error, stale_since gesetzt, Betrieb benachrichtigt (mögliche Passwortänderung oder Sperre). |
| 403 | kein Retry im laufenden Run, kein Breaker. Ein 403 mit valid-sync-token-Fehler führt zu Cursor-Reset und Depth-1-Vergleich; 403 auf Collection-Ebene setzt die Connection auf degraded (degraded_reason = forbidden, mögliche Freigabeänderung); 403 auf einzelner Ressource markiert nur diese Ressource (07-sync-strategy.md Abschnitt 6.1). |
| 405, 501 auf gesperrte Methoden | dürfen nicht auftreten, weil der Methoden-Guard des HTTP-Clients DELETE, MOVE, COPY, PROPPATCH, LOCK, UNLOCK und PUT ohne If-None-Match: * vor dem Senden abbricht. Tritt ein solcher Statuscode dennoch auf, ist das ein Programmierfehler und wird als Vorfall im Audit erfasst. |
| Änderung des Server-Fingerprints (Header, Report-Set, Auth-Schema) | Connection auf degraded, Uploads pausieren, Lesen läuft weiter mit halbierter Rate bis zur Freigabe durch Rolle release. |

### 4.2 CircuitBreaker

- Je Connection, Parameter aus 07-sync-strategy.md Abschnitt 6.2. Öffnet nach 5 Fehlern (5xx, Timeout, 429 ohne Retry-After) innerhalb von 2 Minuten oder sofort bei 401 (dann zusätzlich connection.status = error). 403 öffnet den Breaker nicht.
- Offen: keine Requests, Health-Check pausiert für 10 Minuten.
- Half-Open: genau ein PROPFIND Depth 0. Erfolg schließt den Breaker, Rate startet bei 50 Prozent des konfigurierten Werts und steigt je erfolgreichem Run um 25 Prozentpunkte bis auf 100 Prozent. Fehler öffnet erneut für 10 Minuten, ab dem dritten Fehlzyklus für 60 Minuten mit Meldung an den Betrieb.
- Ein offener Breaker gilt für alle Connections desselben technischen Nutzers (immoware_technical_users), weil das Freigabe-Passwort für alle Freigaben dieses Nutzers gilt.
- Breaker-Zustand in Redis, Zustandswechsel im Audit.

### 4.3 Verteilung und Fairness

- Redis-Lock je Connection und Adapter verhindert parallele Läufe derselben Quelle.
- Alle Connections desselben Hosts teilen sich ein gemeinsames Host-Limit (Summe der rate_limit_rps aller aktiven Connections dieses Hosts darf 2,00 rps nicht überschreiten; Prüfung beim Aktivieren einer Connection; 07-sync-strategy.md Abschnitt 6.3).
- Scheduler versetzt die Startzeiten der Connections (Jitter bis 5 Minuten), damit Posteingang-, Kontakt- und Kalender-Scans nicht gleichzeitig starten.
- Schreibjobs haben Vorrang vor Lesejobs in der Queue, aber ein PUT wartet, bis kein Lesevorgang auf demselben Ordner läuft (Vermeidung von Listing-Inkonsistenzen im Precheck).

### 4.4 Lastnachweis

- sync_runs.counters enthält requests, bytes_down, bytes_up, duration_ms, throttled_429, retries, errors, plus Aufschlüsselung nach HTTP-Methode.
- Wöchentlicher Bericht "Lastprofil Immoware24" (Requests pro Tag je Connection, Spitzenwert pro Minute, Anzahl 429/503) wird als Auswertung im Hub bereitgestellt und quartalsweise im Repository archiviert. Er ist die Grundlage für jede Anpassung der Startwerte und für Gespräche mit dem Immoware24-Support.
- Vor jeder Erhöhung eines Limits: Bericht der letzten vier Wochen ohne 429, 503 oder Breaker-Öffnung, Freigabe durch admin, Eintrag im Audit.

## 5. Sonderfälle

- Bootstrap (Erstimport) und Full Reconcile laufen ausschließlich im Nachtfenster und mit der konfigurierten Rate. Der Bootstrap der CardDAV-Kontakte wird auf zwei Nächte verteilt, falls die Kontaktzahl 20.000 überschreitet.
- Downloads von Dateiinhalten (content_policy hash_on_change oder store) zählen mit ihrem Volumen. Tagesdeckel 2 GB Download je Connection als Startwert; Überschreitung pausiert weitere Downloads bis zum nächsten Tag, Metadaten-Scans laufen weiter.
- Bei Ausfall von Immoware24 (Breaker offen über mehr als 60 Minuten) liefert der Hub Daten aus dem Spiegel mit source_status = stale. Nach Rückkehr des Servers kein Aufholen mit erhöhter Rate; die regulären Intervalle greifen wieder.
- Manuelle Exporte (CSV, DATEV, CAMT) erzeugen keine Last gegenüber Immoware24 und unterliegen keinen Hub-Limits, nur den Export-Erinnerungen aus export_schedules.

## 6. Offene Punkte

| Nr. | Punkt | Kennzeichnung |
|---|---|---|
| R1 | Bestehen serverseitige Rate Limits, Quotas oder Sperrmechanismen für den DAV-Adapter? Gibt es Retry-After-Header? | WAITING_FOR_VENDOR_ACCESS (schriftliche Anfrage an Support) |
| R2 | Maximale Dateigröße für WebDAV-Uploads in den Posteingang | WAITING_FOR_VENDOR_ACCESS, zusätzlich zu verifizieren am eigenen Mandanten mit Testdateien steigender Größe (nur nach Freigabe des Schreibpfads) |
| R3 | Verhalten bei mehreren Fehlanmeldungen (Sperre des Freigabe-Passworts, temporäre Blockade der IP) | zu verifizieren am eigenen Mandanten, maximal drei Fehlversuche in der Probe |
| R4 | Anzahl Ordner und Dateien im DMS-Baum, damit die Dauer des Full Reconcile geplant werden kann | zu verifizieren am eigenen Mandanten (Phase 0) |
| R5 | Wartungsfenster und Ankündigungskanal für Änderungen am DAV-Adapter | WAITING_FOR_VENDOR_ACCESS; bis dahin manuelle Beobachtung der Release Notes im Support-Center |
| R6 | Ob der Adapter Sitzungs-Cookies setzt und wie lange sie gelten | zu verifizieren am eigenen Mandanten |
| R7 | Ob die Empfehlung zur Beschränkung der Freigaben auf ein serverseitiges Limit der Kontakt- oder Terminanzahl je Freigabe hinweist | WAITING_FOR_VENDOR_ACCESS |
