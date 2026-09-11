# 07 Sync-Strategie Immoware Hub

Stand: 11.09.2026
Gesellschaft: Hausverwaltung Müller GmbH (Mandant Immoware24)
Status dieses Dokuments: Entwurf, noch keine Messung am eigenen Mandanten

## 0. Belegstand und Geltungsbereich

Jede Aussage zu Immoware24 in diesem Dokument trägt einen Status nach der verbindlichen Leitdefinition in README.md (VERIFIZIERT, DOKUMENTIERT, VERMUTET, NICHT VERFÜGBAR). Snippet-Paraphrasen ohne gesicherten Wortlaut sind DOKUMENTIERT, Negativbefunde sind NICHT VERFÜGBAR.

Hinweis zur Quellenlage: Die Hosts www.immoware24.de, content.immoware24.de, support.immoware24.de und config.dav.immoware24.de sind aus der Rechercheumgebung gesperrt (EGRESS_BLOCKED). Alle Zitate stammen aus WebSearch-Snippets und sind teilweise paraphrasiert. Sie sind in Phase 0 am Original zu prüfen und im Repository abzulegen.

Zugangswege, auf die sich diese Sync-Strategie stützt:

| Zugangsweg | Status | Quelle | Konsequenz |
|---|---|---|---|
| WebDAV auf DMS (mindestens Ordner Posteingang und Dokumente) | VERIFIZIERT (Existenz, Netzlaufwerk, Upload vom Scanner, Änderungen und Löschungen wirken im Livesystem) | https://content.immoware24.de/content/manual/Anleitung_DAV-Adapter.pdf, https://support.immoware24.de/hc/de/articles/360010770277 | Lesen per PROPFIND, Schreiben ausschließlich create-only in den Posteingang |
| CardDAV Kontaktfreigabe | VERIFIZIERT (Existenz als Freigabetyp Kontakte), Schreibrichtung unklar | https://support.immoware24.de/hc/de/articles/360010876078 | nur lesend |
| CalDAV Kalenderfreigabe | VERIFIZIERT (Existenz), Schreibrichtung unklar, Android-Hinweis Schreibschutz erzwingen nur VERMUTET | https://support.immoware24.de/hc/de/articles/360010887358 | nur lesend, niedrige Priorität |
| sync-token (RFC 6578), CTag, ETag-Stabilität, If-None-Match | NICHT VERFÜGBAR (keine Aussage von Immoware24) | keine | wird durch Probe gemessen, nicht angenommen |
| Rate Limits, Quotas, Sitzungs-Timeouts | NICHT VERFÜGBAR | keine | feste konservative Limits |
| CSV-Export Auswertungen (Export-Button) | DOKUMENTIERT, Spaltenformat NICHT VERFÜGBAR | https://support.immoware24.de/hc/de/articles/360018128817 | Datei-Import mit Header-Fingerprint |
| DATEV-CSV-Buchungsexport | DOKUMENTIERT | https://www.immoware24.de/funktionen/datev/ | Datei-Import, Phase 3 |
| CAMT.053 v02/v08, MT940 STA | DOKUMENTIERT (Importformate in Immoware24) | https://support.immoware24.de/hc/de/articles/4406428565009 | Hub liest nur zur Anzeige |
| DMS-Papierkorb, Löschung nach 7 Tagen | DOKUMENTIERT (AGB, Wortlaut nicht geprüft) | https://www.immoware24.de/agb/ | Fehluploads sind nach 7 Tagen nicht mehr wiederherstellbar |
| Freigabe-Passwort gilt für alle Freigaben eines Nutzers ("Das Passwort sollte von Ihrem Immoware24-Passwort abweichen und gilt für alle Ihre Freigaben.") | VERIFIZIERT | Anleitung zum DAV-Adapter, https://support.immoware24.de/hc/de/articles/360010768217 (Quellenzuordnung siehe 04-authentication.md Abschnitt 2.3); Reset-Folgesatz aus Artikel 360010887358 nur DOKUMENTIERT | Reset betrifft alle Connections dieses Nutzers |

Es gibt keine Push-Benachrichtigung, keine Webhooks und keine Event-Schnittstelle von Immoware24 (NICHT VERFÜGBAR). Ein Event-Sync im Sinne von Immoware24 sendet Ereignisse existiert daher nicht. Event bezeichnet in diesem Dokument ausschließlich Hub-interne Ereignisse (Drop-Ordner-Eingang, manueller Upload, Operator-Aktion).

## 1. Sync-Modi

### 1.1 Full Sync (Erstimport und Full Reconcile)

- Zweck: vollständiger Abgleich einer Collection ohne Vertrauen in Cursor, Fingerprints oder Tokens.
- WebDAV: PROPFIND Depth 1 je Collection, rekursiv über alle bekannten und neu entdeckten Ordner. Ordner-Fingerprints werden ignoriert.
- CardDAV und CalDAV: PROPFIND Depth 1 mit getetag über die gesamte Collection, anschließend multiget der Einträge mit geändertem oder unbekanntem ETag. Fehlt ETag-Stabilität laut Probe, werden alle Einträge geladen und gehasht.
- CSV, DATEV, CAMT: jede Datei ist per Definition ein Full-Snapshot ihres Exporttyps, sofern als Vollexport gekennzeichnet.
- Rhythmus: Erstimport einmalig (Bootstrap, siehe Abschnitt 9), Full Reconcile wöchentlich im Nachtfenster (Abschnitt 1.5).
- Ergebnis: sync_runs.run_type = full_reconcile, Cursor wird neu gesetzt.

### 1.2 Incremental Sync

- Zweck: Erkennung von Änderungen seit dem letzten Lauf mit möglichst wenigen Requests.
- Strategie je Collection kommt aus sync_states.strategy, das die Probe festgelegt hat (Abschnitt 2).
- Intervalle Phase 1 gemäß Abschnitt 1.5. Verkürzung erst nach Sichtung des Lastprofils in sync_runs.counters.
- Ergebnis: sync_runs.run_type = incremental.

### 1.3 Event-getriebener Sync (nur Hub-intern)

- Trigger: Datei im SFTP-Drop-Ordner, Upload in der Hub-UI, Abschluss einer write_operation (Verifikation erzeugt einen Zielscan des Posteingangs), Operator-Klick Jetzt synchronisieren.
- Ausführung: Job in der Queue immoware-sync mit demselben Lock wie der Scheduler. Ein Event-Lauf ersetzt den nächsten geplanten Lauf nicht, sondern läuft zusätzlich.
- Kein Event-Sync von Immoware24 aus, da kein Kanal existiert (NICHT VERFÜGBAR).

### 1.4 Scheduled Sync

- Laravel Scheduler ruft je Connection und Adapter das Kommando hub:sync {connection} --mode=incremental auf, Full Reconcile über hub:sync --mode=full.
- Scheduler prüft vor Start: connection.status in (active, degraded). Bei degraded laufen nur lesende Adapter. Bei paused oder error kein Lauf.
- Probe wöchentlich vor dem Full Reconcile: hub:probe {connection}.

### 1.5 Intervalle und Zeitfenster (Leitdokument)

Diese Tabelle ist die einzige verbindliche Quelle für Intervalle und Zeitfenster. 06-rate-limits.md, data-ownership.md, 02-data-model.md und der Implementierungsplan verweisen hierher und wiederholen keine abweichenden Werte. Alle Zeiten Europe/Berlin. Werte sind Startwerte, Anpassung nur nach Lastprofil und Freigabe durch Rolle admin.

| Lauf | Intervall bzw. Fenster | Bemerkung |
|---|---|---|
| Incremental Posteingang (WebDAV) | alle 30 Minuten | Event-Lauf nach Abschluss einer write_operation zusätzlich |
| Incremental Ordner Dokumente und übrige Ordner (WebDAV) | täglich, 03:00 | metadata_only |
| Incremental CardDAV | alle 60 Minuten | sync-token, CTag oder Depth-1-Vergleich laut Strategie |
| Incremental CalDAV | alle 4 Stunden | niedrige Priorität, Phase 3 |
| Health-Check je Connection | alle 15 Minuten | PROPFIND Depth 0 |
| Probe | wöchentlich, Samstag 21:30, sowie vor dem ersten produktiven Lauf und vor jeder Aktivierung von write_enabled | Fingerprint-Vergleich |
| Full Reconcile (WebDAV, CardDAV, CalDAV) | wöchentlich, Nacht von Samstag auf Sonntag, Start 22:00, Ende spätestens 05:00 | 7 Stunden, ignoriert Fingerprints; ein täglicher Full Reconcile ist nicht vorgesehen |
| Nachtfenster für Volllast (Full Reconcile, Bootstrap) | 22:00 bis 05:00 | bei 2 rps maximal 50.400 Requests je Nacht; Bootstrap CardDAV auf zwei Nächte verteilt, falls mehr als 20.000 Kontakte |
| CSV-Export Stammdaten, Kontakte, OP-Liste | wöchentlich (export_schedules, verantwortliche Person) | manuell ausgelöst in Immoware24 |
| DATEV-Export, CAMT.053 | monatlich bzw. wöchentlich (export_schedules) | manuell ausgelöst |

## 2. Nutzung von CTag, sync-token und ETag

Alle drei Mechanismen sind bei Immoware24 NICHT VERFÜGBAR im Sinne einer Herstellerzusage. Ob der DAV-Server sie liefert, wird gemessen und je Connection persistiert (immoware_connections.probe_result, sync_states.strategy).

### 2.1 Probe (vor Sprint 1, danach wöchentlich)

1. OPTIONS und PROPFIND Depth 0 auf den Freigabe-Root. Auszuwerten: DAV-Header (Klassen 1, 2, 3), supported-report-set (sync-collection, addressbook-multiget, calendar-multiget), Property sync-token, Property calendarserver-getctag, Auth-Challenge (Basic oder Digest), Server-Header.
2. ETag-Stabilität: zwei PROPFIND Depth 1 auf dieselbe Collection im Abstand von 60 Sekunden ohne zwischenzeitliche Änderung. Nur bei vollständig identischen ETags gilt etag_stable = true.
3. If-None-Match: PUT einer Testdatei in den Posteingang mit If-None-Match: *, zweites PUT derselben Datei ebenfalls mit If-None-Match: *. Antwort 412 gilt als unterstützt. Die Testdatei wird anschließend durch einen berechtigten Mitarbeiter manuell im DMS entfernt. Der Hub sendet nie DELETE.
4. Server-Fingerprint: SHA-256 über DAV-Header, Report-Set, Server-Header, Auth-Schema. Änderung zwischen zwei Proben setzt connection.status = degraded.

### 2.2 Strategie-Auswahl je Collection

| Probe-Ergebnis | strategy | Ablauf pro Incremental-Lauf |
|---|---|---|
| sync-collection im Report-Set und sync-token vorhanden | sync_token | REPORT sync-collection mit letztem Token, Ergebnisliste verarbeiten, neues Token erst in finalize committen |
| CTag vorhanden, ETags stabil | ctag_etag | PROPFIND Depth 0, CTag vergleichen. Unverändert: Lauf beendet. Verändert: PROPFIND Depth 1, ETag-Vergleich je Ressource |
| kein CTag, ETags stabil | etag_only | PROPFIND Depth 1, ETag-Vergleich je Ressource |
| ETags instabil oder fehlend, getlastmodified und getcontentlength vorhanden | lastmodified_size_hash | PROPFIND Depth 1, Vergleich Größe plus lastmodified, bei Abweichung Download und Hash gemäß content_policy |
| keine verwertbaren Properties | full_hash | Download und Hash aller Ressourcen je Lauf, Intervall wird automatisch verdoppelt |
| Datei-Import (CSV, DATEV, CAMT) | file_snapshot | Datei-Hash, Header-Fingerprint, row_hash |

Regeln:

- ETag ist nie alleiniges Wahrheitsmerkmal für eine neue Version. Ein ETag-Wechsel ohne Änderung von content_hash (falls vorhanden) oder Größe plus lastmodified wird als sync_events.action = unchanged_meta_noise protokolliert.
- sync-token wird in sync_states.sync_token gespeichert und erst in der Phase finalize committet. Bricht ein Lauf ab, bleibt das alte Token.
- Ungültiges Token (403 mit valid-sync-token-Fehler oder 410): Cursor verwerfen, voller Depth-1-Vergleich, Vorfall im Auditlog.
- REV in vCards und LAST-MODIFIED in iCalendar werden gespeichert, aber nicht als Änderungsmerkmal genutzt.

## 3. Hash-basierte Änderungserkennung

### 3.1 Grundsatz

Kein Zugangsweg liefert ein verlässliches updated_at (NICHT VERFÜGBAR). Primäres Merkmal ist daher der SHA-256 über den normalisierten Datensatz (Spalte checksum), sekundär sind ETag, CTag, sync-token, getlastmodified und getcontentlength.

### 3.2 Normalisierung vor dem Hash

| Quelle | Normalisierung |
|---|---|
| vCard | Zeilenenden auf LF, Zeilenfaltung auflösen, Properties alphabetisch sortieren, REV, PRODID und X-Properties ohne Fachbezug entfernen, Werte trimmen, Telefonnummern auf Ziffern reduzieren |
| iCalendar | wie vCard, zusätzlich DTSTAMP, SEQUENCE nur als Hinweis, RECURRENCE-ID Teil der external_id |
| WebDAV-Datei (metadata_only) | Tupel (Pfad NFC, Größe, lastmodified) |
| WebDAV-Datei (hash_on_change, store) | SHA-256 des Dateiinhalts |
| CSV-Zeile | Zellen trimmen, Zeichensatz nach UTF-8, Trennzeichen normalisieren, Dezimaltrennzeichen vereinheitlichen, Leerspalten entfernen, Spalten in Reihenfolge des import_formats.column_mapping |
| DATEV-Zeile | wie CSV, Betrag in Cent, Datum ISO |
| CAMT.053 Eintrag | kanonisches JSON aus Ntry mit AcctSvcrRef, EndToEndId, Amt, BookgDt, ValDt, RmtInf |

### 3.3 Entscheidungslogik

1. Ressource unbekannt (kein external_mapping): created.
2. Ressource bekannt, checksum identisch: unchanged. Kein Versionseintrag.
3. Ressource bekannt, checksum abweichend: updated, neue Zeile in <tabelle>_versions, sync_version um 1 erhöht, sync_event mit old_checksum, new_checksum, payload_id, detected_by.
4. Ressource fehlt: siehe Mark-and-Sweep (Abschnitt 4).
5. Ressource fehlt und im selben Lauf erscheint eine neue Ressource mit identischer Größe und identischem lastmodified: Move-Prüfung. Liegt ein alter content_hash vor, wird der Kandidat geladen und gehasht. Treffer: moved (external_id aktualisiert, Historie bleibt). Kein alter Hash: moved_probable in der Konfliktqueue, Auflösung frühestens nach zwei weiteren Läufen.

### 3.4 Ordner-Fingerprint (WebDAV)

document_folders.children_fingerprint = SHA-256 der sortierten Kind-Liste (Name, Größe, lastmodified, ETag). Unveränderter Fingerprint erlaubt im Incremental-Lauf das Überspringen des Teilbaums. Der wöchentliche Full Reconcile ignoriert Fingerprints, weil WebDAV-Collections tiefe Änderungen nicht garantiert nach oben melden.

## 4. Mark-and-Sweep statt Truncate

Der Spiegel wird niemals geleert und neu befüllt. Gründe: Versionen und Hub-eigene Zuordnungen (Konfliktentscheidungen, Merges, manuelle Mappings) würden verloren gehen, und ein abgebrochener Lauf würde einen leeren Spiegel hinterlassen.

Ablauf je Lauf:

1. Mark: jede in diesem Lauf gesehene Ressource erhält last_seen_at = Laufbeginn, sync_states.last_seen_run_id = Lauf-ID, consecutive_missing = 0.
2. Sweep-Kandidaten: alle Ressourcen der Collection mit last_seen_run_id ungleich Lauf-ID. Voraussetzung: Der Lauf hat die Collection vollständig enumeriert (sync_runs.counters.full_enumeration = 1 je Collection). Das ist nur der Fall bei den Strategien etag_only, lastmodified_size_hash und full_hash sowie beim Full Reconcile. Bei sync_token liefert REPORT sync-collection nur Änderungen, bei ctag_etag endet ein Lauf mit unverändertem CTag ohne Ressourcenliste, und ein übersprungener Ordner-Fingerprint liefert keine Kind-Liste; in diesen drei Fällen findet weder Mark noch Sweep statt, unveränderte Ressourcen behalten ihr last_seen_run_id und erhalten kein missing_since. Gelöschte Ressourcen aus einem sync-collection-Ergebnis (Status 404 im REPORT) werden nicht gesweept, sondern wie eine im Full Reconcile fehlende Ressource behandelt (missing_since, Soft Delete erst nach Bestätigung durch den nächsten vollständig enumerierenden Lauf).
3. Erstes Fehlen: missing_since setzen, consecutive_missing = 1. Kein Soft Delete.
4. Zweites Fehlen in einem Folgelauf, der die Bedingungen erfüllt: deleted_at setzen, deletion_reason = missing_twice, sync_event soft_deleted.
5. Bedingungen für Soft Delete: beide Läufe haben sync_runs.health_ok_before = 1, beide Läufe erreichten die Phase finalize, der Ordner bzw. die Collection war in beiden Läufen erreichbar (kein 404, 403, 5xx auf Collection-Ebene), die Anzahl der Fehlenden überschreitet nicht die Schutzgrenze.
6. Schutzgrenze: Fehlen in einem Lauf mehr als 20 Prozent oder mehr als 500 Ressourcen einer Collection, wird kein Soft Delete durchgeführt, der Lauf endet mit phase = done und Konflikttyp uncertain_identity auf Collection-Ebene, die Connection erhält degraded_reason = mass_missing. Ein Operator prüft, ob eine Freigabe geändert oder ein Ordner verschoben wurde.
7. Wiederauftauchen einer soft-gelöschten Ressource mit identischer external_id: restored, deleted_at = NULL, sync_event restored.
8. Datei-Importe: Soft Delete nur bei zwei aufeinanderfolgenden, als Vollexport gekennzeichneten Importen desselben Exporttyps und Objekts ohne Treffer. Teilexporte löschen nie.

Hard Delete existiert auf Spiegel-Tabellen nicht. Personenbezogene Felder werden nach Ablauf der konfigurierten Frist mit NULL überschrieben und im Audit vermerkt.

## 5. Locking

| Ebene | Mechanismus | Zweck |
|---|---|---|
| Connection plus Adapter | Redis-Lock hub:sync:{connection_id}, TTL = 2 mal erwartete Laufdauer, mindestens 30 Minuten, Verlängerung per Heartbeat alle 60 Sekunden | kein paralleler Lauf derselben Quelle, auch nicht Incremental neben Full |
| Collection | Redis-Lock hub:collection:{connection_id}:{collection_path_hash}, TTL 15 Minuten | Event-Lauf auf Posteingang darf nicht in einen laufenden Full Reconcile derselben Collection schreiben |
| Cursor-Commit | Datenbanktransaktion in finalize, sync_states mit SELECT FOR UPDATE | Cursor wird nur committet, wenn alle Ressourcen des Laufs verarbeitet sind |
| write_operations | Unique (idempotency_key), Trigger put_attempts maximal 1, Redis-Lock hub:write:{idempotency_key} | genau ein PUT je Zielpfad und Inhalt |
| Datei-Import | Unique (connection_id, payload_type, dedup_hash) in external_payloads, wirksam nur für csv_file, datev_file, camt_file | Duplikatdatei wird abgewiesen, bevor Zeilen gelesen werden; DAV-Antworten werden je Lauf neu archiviert |

Regeln:

- Lock-Verlust während des Laufs (Redis-Ausfall, TTL abgelaufen) führt zu phase = aborted, kein Cursor-Commit, keine Soft Deletes. Der nächste Lauf beginnt mit dem alten Cursor.
- Ein Full Reconcile wartet nicht auf einen laufenden Incremental-Lauf, sondern wird im nächsten Scheduler-Tick erneut versucht; nach drei vergeblichen Versuchen Meldung an den Betrieb.
- Locks werden ausschließlich vom Besitzer freigegeben (Token-Vergleich), nie blind gelöscht.

## 6. Retry-Stufen und Circuit Breaker

Dieser Abschnitt ist das Leitdokument für Retry-Stufen, Breaker-Parameter, Statuswechsel nach 401 und 403 sowie feste Limits. 06-rate-limits.md und 04-authentication.md verweisen hierher; bei Abweichung gilt dieser Abschnitt.

### 6.1 Retry-Stufen

| Versuch | Wartezeit | Anmerkung |
|---|---|---|
| 1 | 30 Sekunden | plus Jitter 0 bis 10 Sekunden |
| 2 | 2 Minuten | plus Jitter 0 bis 30 Sekunden |
| 3 | 10 Minuten | plus Jitter 0 bis 60 Sekunden |
| 4 | 30 Minuten | plus Jitter 0 bis 120 Sekunden |
| 5 | Dead Letter Queue | dlq_items, manuelle Wiederaufnahme durch Rolle operator |

Retry-fähig: 429, 502, 503, 504, Timeouts, Verbindungsabbrüche, 5xx ohne Body. Bei 429 mit Retry-After wird der größere Wert aus Retry-After und Stufe verwendet.

Nicht retry-fähig (sofort DLQ bzw. Konflikt): 401 (Breaker öffnet, connection.status = error, stale_since gesetzt, Secret-Rotation prüfen; Ausnahme: während einer laufenden Rotation nach 08-security.md Abschnitt 2.3 bleibt die Connection paused), 403 auf Collection-Ebene (Freigabe fehlt oder Rolle ohne DAV-Berechtigung: Connection degraded mit degraded_reason = forbidden, kein Breaker), 403 mit valid-sync-token-Fehler (Cursor verwerfen, Depth-1-Vergleich), 403 auf einzelner Ressource (Ressource als fehlerhaft markiert, Lauf läuft weiter), 404 auf Collection-Ebene (Freigabe geändert, Connection degraded), 405 und 501 (Methode nicht unterstützt, Strategie anpassen), 412 beim PUT (skipped_exists), 409 beim PUT (Zielordner fehlt, Konflikt write_target_exists bzw. Pfad-Allowlist prüfen), 4xx bei einzelner Ressource (Ressource wird als fehlerhaft markiert, Lauf läuft weiter).

Sonderregel Schreibpfad: Nach einem PUT mit Netzwerkfehler ohne verwertbare Antwort ist der Status unknown. Es gibt keinen Retry des PUT. Der einzige zulässige Folgeschritt ist PROPFIND auf den Zielpfad, dreimal im Abstand von je 5 Minuten. Danach failed und manuelle Entscheidung.

### 6.2 Circuit Breaker je Connection

- Zustand in Redis hub:breaker:{connection_id}.
- Closed: normaler Betrieb.
- Open: nach 5 Fehlern (5xx, Timeout, 429 ohne Retry-After) innerhalb von 2 Minuten oder sofort nach einem 401. Keine Requests, alle Jobs der Connection werden mit Stufe 4 (30 Minuten) neu eingeplant, Datensätze der Connection erhalten stale_since. Ein 403 öffnet den Breaker nicht (siehe 6.1).
- Half-Open: nach 10 Minuten ein einzelner Health-Check (PROPFIND Depth 0). Erfolg schließt den Breaker; die Rate startet bei 50 Prozent des konfigurierten Werts und steigt je erfolgreichem Lauf um 25 Prozentpunkte bis auf 100 Prozent. Fehler öffnet ihn erneut für 10 Minuten, ab dem dritten Fehlzyklus für 60 Minuten und Meldung an den Betrieb. Keine weitere Verdoppelung der Wartezeit.
- Der Breaker-Zustand wird zusätzlich am technischen Nutzer geführt (immoware_technical_users.breaker_state). Ein 401 auf einer beliebigen Connection eines Nutzers öffnet den Breaker für alle Connections dieses Nutzers (z. B. CardDAV und WebDAV von hub-read), weil ein Freigabe-Passwort für alle Freigaben eines Nutzers gilt (VERIFIZIERT). Lese- und Schreibnutzer sind verschieden (04-authentication.md Abschnitt 3.1); ein Breaker des Lesenutzers pausiert den Schreibnutzer daher nicht automatisch. Uploads pausieren stattdessen, wenn die gekoppelte Lese-Connection (paired_read_connection_id) degraded oder im Breaker ist, weil dann die Verifikation und der Zielscan nicht möglich sind.

### 6.3 Feste Limits

Rate 2 Requests pro Sekunde je Host als Summe über alle aktiven Connections dieses Hosts (die Werte rate_limit_rps der Connections eines Hosts dürfen zusammen 2,00 nicht überschreiten, Prüfung beim Aktivieren einer Connection), Concurrency 2 lesend, 1 schreibend, feste Pause 500 Millisekunden zwischen PROPFIND-Requests, Paketgröße multiget 50 hrefs. Kein Token-Bucket-Subsystem, die Werte sind Konfiguration in immoware_connections. Da Immoware24 keine Limits veröffentlicht (NICHT VERFÜGBAR), wird das Lastprofil in sync_runs.counters festgehalten und dem Support auf Anfrage vorgelegt.

## 7. Konfliktmatrix

Konflikte blockieren nur den betroffenen Datensatz, nie den Lauf. Sie landen in conflicts mit conflict_type und werden von der Rolle operator bearbeitet. Je Datensatz und Konflikttyp existiert höchstens ein offener Eintrag: Stellt ein Folgelauf denselben Konflikt erneut fest (z. B. moved_probable alle 30 Minuten), wird der bestehende Eintrag aktualisiert (occurrences, last_seen_run_id, last_seen_at), nicht neu angelegt (Datenmodell, Tabelle conflicts).

| Situation | Erkennung | conflict_type | Automatik | Manuelle Auflösung |
|---|---|---|---|---|
| CSV-Zeile ohne vollständigen Schlüssel laut key_schema | Mapper | uncertain_identity | Zeile wird mit identity_confidence = uncertain gespeichert, external_id = "uncertain:" plus row_hash, kein external_mapping. Solche Zeilen werden nicht versioniert und nicht per Mark-and-Sweep behandelt: Jeder Vollexport desselben Exporttyps und Objekts ersetzt die Menge der uncertain-Zeilen dieses Objekts (alte Zeilen deleted_at, deletion_reason = superseded_uncertain, neue Zeilen angelegt). Ordnet der Operator eine Zeile zu, erhält sie die fachliche external_id und wird ab dann regulär versioniert | Operator ordnet zu (dann exact oder derived) oder verwirft |
| Zwei Ressourcen mit derselben external_id in einem Lauf | Reconciler | duplicate_external | beide gespeichert, keine Zusammenführung | Operator entscheidet, welche gilt |
| Zwei Kontakte mit identischem similarity_hash oder zwei Freigaben mit abweichender UID für dieselbe Person | Reconciler | duplicate_candidate | nur Vorschlag | Merge ausschließlich als merged_into_id mit Rückgängig |
| Ressource fehlt, Kandidat mit gleicher Größe und lastmodified ohne alten Hash | Reconciler | moved_probable | kein Soft Delete, kein Move | nach zwei Läufen Entscheidung durch Operator oder automatisch Löschung plus Neuanlage |
| Zwei Immoware24-Quellen (CardDAV und CSV) liefern für einen verknüpften Kontakt abweichende Feldwerte | Reconciler | source_mismatch | CardDAV-Zeile bleibt führend und unverändert, CSV-Wert wird nur im Konflikt festgehalten, keine Scheinversion | Operator prüft in Immoware24, Korrektur über proposed_change |
| Hub-Nutzer hat Annotation oder Zuordnung gesetzt, entfernter Datensatz ändert sich | Reconciler | local_change_vs_remote | entfernte Version wird als neue Version gespeichert, Hub-Zuordnung bleibt | resolved_keep_remote oder resolved_keep_local |
| Unbekannter CSV-Header, abweichendes Trennzeichen oder Zeichensatz | Importer | format_mismatch | Datei in Quarantäne, Exporttyp gestoppt | Operator legt import_formats Version an oder bestätigt Zuordnung |
| Upload verifiziert, Länge oder Hash weicht ab | WriteOperationService | write_verify_failed | status failed_verify, kein zweites PUT | Mitarbeiter prüft im DMS, ggf. manuelle Bereinigung innerhalb 7 Tagen (Papierkorb, DOKUMENTIERT) |
| Zielpfad existiert im Precheck oder Antwort 412 | WriteOperationService | write_target_exists | status skipped_exists | Operator prüft, ob Datei bereits eingegangen ist |
| PUT ohne Antwort, PROPFIND findet Datei dreimal nicht | WriteOperationService | write_unknown_unresolved | status failed | Mitarbeiter prüft Posteingang im DMS, dann neue write_operation mit neuem Dateinamen |
| Änderungswunsch an Immoware24-Daten aus dem Hub | UI oder API | proposed_change | kein Writeback | Mitarbeiter setzt in Immoware24 um, nächster Sync bestätigt über Hash (confirmed_by_sync_run_id) |
| Server-Fingerprint geändert | Probe | kein conflicts-Eintrag, Connection degraded | Uploads pausieren, Lesen läuft | Rolle release schaltet nach Prüfung zurück |
| Massenfehlen über Schutzgrenze | Sweep | uncertain_identity auf Collection | kein Soft Delete, degraded_reason = mass_missing | Operator prüft Freigabe und Ordnerstruktur |

Grundsatz: Immoware24 ist Master. Bei jedem Konflikt zwischen entferntem Fachdatum und Hub-Kopie gewinnt der entfernte Wert im Spiegel; Hub-eigene Ergänzungen (Zuordnungen, Fälle, Notizen) bleiben und werden nie nach Immoware24 zurückgeschrieben.

## 8. Testreihenfolge 1 / 10 / 100 / 1000 / alle

Gilt für jeden Adapter und jede Connection vor dem Produktivbetrieb sowie nach jeder Strategieänderung. Jeder Schritt wird als sync_run mit trigger_source = manual protokolliert und im Testprotokoll (docs/immoware/10-test-report.md) festgehalten.

| Stufe | Umfang | Ziel | Abnahmekriterium |
|---|---|---|---|
| 1 | eine Ressource (eine Datei, ein Kontakt, eine CSV-Zeile) | Mapping, Payload-Archiv, Versionierung, Audit | genau ein created, Replay aus external_payloads liefert identische checksum |
| 10 | zehn Ressourcen, davon eine anschließend in Immoware24 manuell geändert | Hash-Änderungserkennung, unchanged_meta_noise | genau ein updated, neun unchanged, keine Konflikte |
| 100 | hundert Ressourcen, eine in Immoware24 manuell verschoben (nur Ordner mit hash_on_change), eine gelöscht | Move-Erkennung, Mark-and-Sweep über zwei Läufe | moved korrekt, missing_since nach Lauf 1, soft_deleted nach Lauf 2 |
| 1000 | tausend Ressourcen | Lastprofil, Rate, Lock-TTL, Paketgröße | Requests pro Sekunde kleiner gleich 2, keine 429, Laufdauer und counters dokumentiert |
| alle | gesamte Freigabe bzw. gesamter Exporttyp (67 Objekte, 869 Einheiten, rund 4.600 Kontakte laut Bestand Stichtag 01.07.2026) | Bootstrap dry_run, Kollisionsbericht, accept_all_exact | Konfliktqueue enthält nur echte Kollisionen und leere Schlüssel, Bootstrap-Bericht im Repository |

Regeln:

- Jede Stufe wird zuerst gegen den Mock-Server (tests/mock-immoware) und erst danach gegen den eigenen Mandanten gefahren.
- Am Mandanten nur mit dem dedizierten technischen Nutzer der Hausverwaltung Müller GmbH, nach Buchung des DAV-Moduls (VERIFIZIERT als buchbar über Support oder Vertrieb, Artikel 360010876038) und nach Freigabe durch die Geschäftsführung.
- Stufe 1 und 10 des Schreibpfads erfolgen ausschließlich mit gekennzeichneten Testdokumenten (Dateiname beginnt mit HUBTEST_), die ein berechtigter Mitarbeiter innerhalb von 7 Tagen manuell aus dem DMS entfernt.
- Ein Fehlschlag auf einer Stufe stoppt den Aufstieg; Wiederholung erst nach Ursachenanalyse und Eintrag im Testprotokoll.

## 9. Bootstrap (Erstimport)

1. Phase 0 liefert je Exporttyp eine echte Datei aus dem eigenen Mandanten. Daraus entsteht import_formats Version 1 mit status = draft (key_schema ist bis dahin NICHT VERFÜGBAR).
2. hub:bootstrap --mode=dry_run: alle Zeilen werden gemappt, Mappings nur vorgeschlagen, Bericht mit Schlüsselkollisionen, leeren Schlüsseln, Ähnlichkeitsgruppen.
3. Operator bestätigt key_schema (import_formats.confirmed_by, confirmed_at, status = active).
4. hub:bootstrap --mode=accept_all_exact: eindeutige Schlüssel werden ohne Konflikteintrag als identity_confidence = exact angelegt, created_by = bootstrap.
5. Auto-Link CardDAV zu CSV nur bei exakter Übereinstimmung von E-Mail, Nachname und Vorname mit genau einem Kandidaten. Danach wird der Auto-Link deaktiviert.
6. Bootstrap-Bericht wird unter docs/immoware/test-protocols/ abgelegt.

## 10. Offene Punkte

| Punkt | Status | Klärung |
|---|---|---|
| Liefert der DAV-Server sync-token, CTag, stabile ETags | NICHT VERFÜGBAR | Probe in Phase 0, zu verifizieren am eigenen Mandanten |
| Unterstützt der Server If-None-Match: * | NICHT VERFÜGBAR | Probe in Phase 0, zu verifizieren am eigenen Mandanten |
| Auth-Schema des DAV-Endpunkts (Basic oder Digest) | VERMUTET | Probe in Phase 0 |
| Tatsächlicher Ordnerumfang per WebDAV (nur Posteingang und Dokumente oder gesamte Objektstruktur) | VERMUTET | Test am eigenen Mandanten |
| Kann eine Dateifreigabe auf den Posteingang beschränkt werden | VERMUTET | WAITING_FOR_VENDOR_ACCESS, Test im Konfigurationsportal |
| Welche Nutzerrolle darf DAV-Freigaben tragen | NICHT VERFÜGBAR | Test am eigenen Mandanten |
| Aufteilung der Kontaktfreigabe nach Kontakttypen | VERMUTET | Test am eigenen Mandanten |
| Spaltenformat aller CSV-Exporte, DATEV-Datei, key_schema | NICHT VERFÜGBAR | Sichtung echter Exportdateien in Phase 0 |
| Zulässigkeit automatisierten WebDAV-Zugriffs laut AGB und Support | NICHT VERFÜGBAR | WAITING_FOR_VENDOR_ACCESS, schriftliche Anfrage an Immoware24-Support, Prüfung durch Rechtsanwalt |
| Rate Limits, Quotas, Sitzungs-Timeouts | NICHT VERFÜGBAR | WAITING_FOR_VENDOR_ACCESS, bis dahin feste Limits |
| Kosten des DAV-Moduls | NICHT VERFÜGBAR | Anfrage Vertrieb, Freigabe Geschäftsführung |
| Wortlaut der Snippet-Zitate (Anleitung DAV-Adapter, Support-Artikel, AGB) | DOKUMENTIERT, Original nicht gesichtet | Sicherung aus Umgebung ohne Egress-Sperre, Ablage im Repository |

Alle Punkte mit Status VERMUTET oder NICHT VERFÜGBAR dürfen bis zur Klärung keine Bauentscheidung tragen.
