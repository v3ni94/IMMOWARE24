# 05 Write-Capabilities: Schreibrechte, Feature-Flags, Idempotenz

Stand: 11.09.2026, Änderungsvermerk 12.09.2026 (Statuswerte, Capability-Schlüssel, Trigger)
Bezug: Architekturentscheidung Abschnitte 1, 3.3, 6 und 8; Datenmodell Tabellen capabilities, immoware_connections, write_operations, conflicts, audit_logs

## 0. Grundsatz

Der Hub ist standardmäßig vollständig read-only. Es existiert genau eine Schreiboperation gegen Immoware24: eine neue Datei per WebDAV in den DMS-Ordner Posteingang legen (webdav_create). Alles andere ist nicht nur deaktiviert, sondern auf drei Ebenen gesperrt (Konfiguration mit BootGuard, Datenbank-Trigger, Methoden-Guard im HTTP-Client). Änderungsvermerk 12.09.2026: Die Datenbank-Ebene umfasst im Code die Trigger aus docs/architecture/03-mariadb-triggers.sql (audit_logs append-only; write_operations mit put_attempts <= 1, ohne Rückweg hinter sent oder unknown, nur operation webdav_create), angelegt nur auf MariaDB/MySQL. Die Freigabeprüfungen write_enabled (Vier-Augen) und capabilities.enabled sind ausschließlich Anwendungslogik, kein Trigger. Ein DELETE-Writeback existiert nicht und wird nicht gebaut.

## 1. Belegstand der Schreibfähigkeiten

| Fähigkeit | Immoware24-Beleg | Status | Hub-Entscheidung |
|---|---|---|---|
| Datei-Upload per WebDAV in den Posteingang | "Dokumente direkt vom Scanner in das Immoware24 System hochladen" (Anleitung DAV-Adapter, © 2023 Immoware24 GmbH, Snippet gesehen); ergänzend Toshiba-Seite: "Die gescannten Dokumente landen via WebDAV-Schnittstelle zur Weiterverarbeitung im sog. 'Posteingang'" (DOKUMENTIERT, in der Gegenprüfung nicht reproduzierbar) | VERIFIZIERT (Upload durch Geräte, gestützt auf die Anleitung) | Einziger Schreibpfad. Zulässigkeit eines Uploads durch eine Serveranwendung ist nicht belegt: WAITING_FOR_VENDOR_ACCESS |
| Überschreiben per WebDAV | "Die Ordner 'Posteingang' und 'Dokumente' können überschrieben werden. Änderungen und Löschvorgänge wirken sich auf das eingebundene System aus." (Snippet) | VERIFIZIERT (technisch möglich, wirkt sofort im Livesystem) | hard_locked, nie genutzt |
| Löschen per WebDAV | dito, Papierkorb wird nach 7 Tagen automatisiert geleert (AGB-Snippet, DOKUMENTIERT) | VERIFIZIERT (technisch möglich) | hard_locked, nie genutzt, kein DELETE-Writeback |
| MOVE, COPY, PROPPATCH, LOCK, UNLOCK, MKCOL | keine Aussage | NICHT VERFÜGBAR | Methoden-Guard blockiert |
| Schreiben in Ordner Dokumente | Ordner ist beschreibbar (VERIFIZIERT) | | allowed_write_prefix schließt den Ordner aus, writable_by_hub = 0 |
| CardDAV schreiben (Kontakt anlegen oder ändern) | Keine offizielle Aussage; Snippet "nur lesend" nicht zuordenbar (VERMUTET); sync.blue-Werbung "2-Wege" ist Drittanbieteraussage (VERMUTET) | unklar | hard_locked, Rückweg nur über proposed_change |
| CalDAV schreiben (Termin anlegen oder ändern) | Android-Hinweis "Schreibschutz erzwingen, um Änderungen in der Immoware24 Software zu verhindern" (Snippet, VERMUTET) | unklar | hard_locked, Rückweg nur über proposed_change |
| Stammdaten schreiben (Objekt, VE, Vertrag, Kontaktrolle) | Kein Zugangsweg belegt, keine API | NICHT VERFÜGBAR | Nicht vorgesehen, proposed_change |
| Buchhaltung schreiben (Buchung, OP, Zahlungsauftrag, Kontoauszug einspielen) | Nur UI und Banking-Client belegt (DOKUMENTIERT) | NICHT VERFÜGBAR für Hub | Nicht vorgesehen |
| Ticket anlegen | Nur UI, E-Mail-Umwandlung, Portal24, KI-Anrufbeantworter (DOKUMENTIERT) | NICHT VERFÜGBAR für Hub | Nicht vorgesehen. Hub-Vorgang kann als Dokument in den Posteingang gelegt werden, das ist kein Ticket |

Rechtemodell auf Immoware24-Seite: Ob eine Dateifreigabe auf den Posteingang beschränkt werden kann und welche Nutzerrolle DAV tragen darf, ist VERMUTET bzw. NICHT VERFÜGBAR (Phase-0-Testpunkte). Ist keine Beschränkung möglich, liegt der Schutz vollständig bei den Code-Guards dieses Dokuments; das ist im Freigabeprotokoll der Geschäftsführung ausdrücklich zu benennen.

## 2. Drei Sperrebenen

### 2.1 Konfiguration (Feature-Flags, ENV)

Alle Flags haben den Default aus, sind in config/immoware.php gekapselt und werden zur Laufzeit nur gelesen, nie geschrieben. Ein Flag allein schaltet nichts frei; die Freigabe je Connection (2.2) ist zusätzlich erforderlich.

| ENV-Variable | Default | Wirkung | Hinweis |
|---|---|---|---|
| IMMOWARE_WRITE_ENABLED | false | Globaler Hauptschalter für den WriteOperationService. false: jede Schreibanforderung wird mit CapabilityDenied abgewiesen und als pending belassen (kein Verlust der Anforderung, Inhalt referenzierbar abgelegt), Auditeintrag write.denied_global | Wird erst nach Freigabe der Geschäftsführung in Phase 2 auf true gesetzt |
| IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED | false | Operation webdav_create erlaubt | Einzige Operation, die je auf true stehen darf |
| IMMOWARE_WRITE_WEBDAV_OVERWRITE_ENABLED | false | Fest verdrahtet: wird gelesen, aber ein Wert true führt beim Boot zu einer Exception und verhindert den Start der Anwendung | Sicherung gegen Fehlkonfiguration |
| IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED | false | Fest verdrahtet wie oben, true verhindert den Start | DELETE-Writeback existiert nicht |
| IMMOWARE_WRITE_WEBDAV_MOVE_ENABLED | false | Fest verdrahtet wie oben | |
| IMMOWARE_WRITE_CARDDAV_ENABLED | false | Fest verdrahtet wie oben | |
| IMMOWARE_WRITE_CALDAV_ENABLED | false | Fest verdrahtet wie oben | |
| IMMOWARE_WRITE_ALLOWED_PREFIX | /Posteingang/ | Standardwert für immoware_connections.allowed_write_prefix neuer Schreib-Connections | Exakte Schreibweise zu verifizieren am eigenen Mandanten, dann fest eintragen |
| IMMOWARE_WRITE_MAX_UPLOAD_BYTES | 26214400 | 25 MB, keine Immoware24-Angabe belegt (NICHT VERFÜGBAR) | |
| IMMOWARE_WRITE_VERIFY_WITH_HASH | true | GET plus SHA-256 nach Upload | Abschaltbar nur je Connection mit Begründung im Audit |
| IMMOWARE_WRITE_UNKNOWN_PROPFIND_ATTEMPTS | 3 | Anzahl PROPFIND-Prüfungen bei Status unknown | |
| IMMOWARE_WRITE_UNKNOWN_PROPFIND_INTERVAL_SECONDS | 300 | Abstand der Prüfungen | |
| IMMOWARE_WRITE_MAX_CONCURRENCY | 1 | Schreibende Requests gleichzeitig je Host | |
| IMMOWARE_WRITE_DRY_RUN | false | true: alle Schritte bis einschließlich Precheck werden ausgeführt und protokolliert, kein PUT | Für den Testlauf in Phase 2 |
| IMMOWARE_READ_ENABLED | true | Lesepfad. false: alle Connections gelten als paused | |

HUB_BOOT_GUARD (Default true) steuert die Boot-Prüfung der fest verdrahteten Flags. Ein Wert false wirkt nur in den Umgebungen local und testing; in production und staging wird false ignoriert, mit Warnung im Log, und der Guard läuft (BootGuard::shouldRun, config/hub/core.php, Änderungsvermerk 12.09.2026).

Zusätzliche Flags außerhalb des Schreibpfads, hier nur zur Abgrenzung: HUB_WEBHOOKS_ENABLED (Default false, ausgehende Webhooks), HUB_API_KEYS_ENABLED (Default false, keine Keys in Phase 1). Die Datei .env.example übernimmt die Variablennamen dieses Abschnitts unverändert; sie ist keine eigene Statusquelle.

### 2.2 Freigabe je Connection (Datenbank)

Eine Schreiboperation ist nur möglich, wenn für die betroffene immoware_connections-Zeile gilt:

1. connector_type = webdav_documents und purpose = write (eigener technischer Nutzer, getrennt von der Lese-Connection).
2. write_enabled = 1, gesetzt durch write_enabled_by (Rolle admin) und bestätigt durch write_confirmed_by (Rolle release), beide Personen verschieden (zwei release-Nutzer ohne admin genügen nicht, Rollenmodell in 08-security.md Abschnitt 4), write_approval_document_id verweist auf das protokollierte Freigabedokument der Geschäftsführung. Die Anwendungsprüfung erzwingt dies (Datenmodell A); ein Trigger dafür ist nicht umgesetzt (Änderungsvermerk 12.09.2026).
3. status = active. Bei degraded, paused oder error pausieren alle Uploads automatisch, bleiben pending und werden nach Rückschaltung durch die Rolle release fortgesetzt.
4. capabilities-Zeile documents.write (Konzeptname webdav.create) mit enabled = 1; die CapabilityRegistry verlangt dafür evidence_status in (VERIFIZIERT, DOKUMENTIERT), tested_at gesetzt, hard_locked = 0, test_protocol hinterlegt (Anwendungslogik, kein Trigger, Änderungsvermerk 12.09.2026).
5. allowed_write_prefix gesetzt; Zielpfad muss mit dem Präfix beginnen (nach NFC-Normalisierung und Auflösung von Punktsegmenten, ".." führt immer zur Ablehnung).

Capability-Matrix (Soll-Zustand jeder Connection):

| capability_key | evidence_status | enabled | hard_locked |
|---|---|---|---|
| webdav.list | VERIFIZIERT | nach Probe 1 | 0 |
| webdav.read | VERIFIZIERT | nach Probe 1 | 0 |
| webdav.create | VERIFIZIERT (Upload durch Geräte), Zulässigkeit für Server: WAITING_FOR_VENDOR_ACCESS | nur Schreib-Connection, nach Freigabe | 0 |
| webdav.overwrite | VERIFIZIERT (technisch möglich) | 0 | 1 |
| webdav.delete | VERIFIZIERT (technisch möglich) | 0 | 1 |
| webdav.move | NICHT_VERFUEGBAR | 0 | 1 |
| carddav.read | VERIFIZIERT (Existenz) | nach Probe 1 | 0 |
| carddav.write | VERMUTET | 0 | 1 |
| caldav.read | VERIFIZIERT (Existenz) | nach Probe 1 | 0 |
| caldav.write | VERMUTET | 0 | 1 |
| csv.import.*, datev.import, camt.import | DOKUMENTIERT | nach Formatfreigabe 1 | 0 |

hard_locked = 1 kann nur per Migration geändert werden, nicht über UI oder Artisan-Kommando.

Änderungsvermerk 12.09.2026 (Abgleich mit dem Code): Die Tabelle capabilities und die Admin-Seite verwenden fachliche Schlüssel (App\Modules\Connector\Enums\CapabilityKey, config/hub/connector.php). Zuordnung zu den oben genannten Zugangsweg-Schlüsseln: webdav.list und webdav.read entsprechen documents.read; webdav.create entspricht documents.write (beide Flags IMMOWARE_WRITE_ENABLED und IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED erforderlich); webdav.overwrite entspricht documents.overwrite (hard_locked, seit 12.09.2026 als eigene Zeile sichtbar); webdav.delete entspricht documents.delete; webdav.move entspricht documents.move; carddav.read und carddav.write entsprechen contacts.read und contacts.write; caldav.read und caldav.write entsprechen calendar.read und calendar.write; csv.import.*, datev.import und camt.import werden über properties.read, units.read, contracts.read und finance.read (Adapter file_import) abgebildet. Freigabeprotokolle nennen beide Schreibweisen. Die Sperre gesperrter HTTP-Methoden (DELETE, MOVE, COPY, PROPPATCH, LOCK, UNLOCK, MKCOL, POST, PATCH) ist im Code als Konstante fest verdrahtet (HttpClientFactory::BLOCKED_METHODS), es gibt keine Konfigurationsoption; PUT passiert den Guard nur mit If-None-Match: *, aus einer Connection mit purpose write und auf einen Pfad unterhalb von allowed_write_prefix; CardDAV- und CalDAV-Kontexte lassen ausschließlich OPTIONS, PROPFIND, REPORT, GET und HEAD zu.

### 2.3 Methoden-Guard im HTTP-Client (Code)

Der WebDAV-Client des Hubs (eigene Klasse um Guzzle bzw. den Laravel HTTP-Client, ein einziger Einstiegspunkt) besitzt eine Middleware, die unabhängig von jeder Konfiguration und Datenbank folgende Requests mit einer ForbiddenMethodException abbricht, bevor eine Verbindung aufgebaut wird:

- Methoden DELETE, MOVE, COPY, PROPPATCH, LOCK, UNLOCK, MKCOL, PATCH, POST gegen jeden Immoware24-Host.
- PUT ohne Header If-None-Match: * (exakt dieser Wert).
- PUT, dessen Zielpfad nicht mit dem allowed_write_prefix der übergebenen Connection beginnt.
- PUT gegen eine Connection mit purpose = read.
- Jede Methode außer OPTIONS, PROPFIND, REPORT, GET, HEAD gegen CardDAV- und CalDAV-Connections.

Die Middleware ist nicht deaktivierbar, hat keine Konfigurationsoption und wird durch Unit-Tests abgesichert, die jede verbotene Methode einmal auslösen. Ein Codeänderungs-Review durch eine zweite Person ist für diese Klasse verpflichtend (CODEOWNERS).

## 3. Idempotenz-Tabelle write_operations

Vollständige Spaltenliste im Datenmodell (Abschnitt E). Hier die Semantik.

### 3.1 Idempotenzschlüssel

idempotency_key = SHA-256(connection_id || content_hash || intent_key), Unique. Der Zielpfad ist ausdrücklich nicht Bestandteil, weil er einen je Operation neu erzeugten UUIDv7-Suffix trägt und zwei Anträge mit identischer Datei sonst zwei Dateien im Posteingang des Livesystems erzeugen würden.

intent_key (Spalte write_operations.intent_key) ist der fachliche Kontext des Antrags:
- API-Aufruf: der Pflichtheader Idempotency-Key (UUID) des Aufrufers (09-api-documentation.md Abschnitt 1).
- UI-Antrag durch Operator: SHA-256(source_document_id) bzw. SHA-256(case_id || original_filename), falls einer dieser Bezüge gesetzt ist; sonst eine vom Hub je Antrag erzeugte UUID, wobei die UI vor dem Anlegen prüft, ob für (connection_id, content_hash) bereits eine Operation in status verified, sent oder unknown existiert, und in diesem Fall eine ausdrückliche Bestätigung verlangt (Hinweis duplicate_content im precheck_result).
- system (z. B. Mailimport): SHA-256(source_document_id), Pflichtfeld.

Reihenfolge: Zuerst wird der idempotency_key gebildet und gegen write_operations geprüft; erst wenn kein Datensatz existiert, wird der UUIDv7-Suffix vergeben und daraus target_path gebildet.

Folge: Derselbe Inhalt im selben fachlichen Kontext derselben Connection ist genau eine Operation. Ein zweiter Aufruf mit gleichem Schlüssel gibt ohne Netzwerkzugriff den bestehenden Datensatz zurück, wenn dessen status in (pending, prechecked, sent, unknown, verified, rejected). Bei status failed wird kein automatischer zweiter Versuch erzeugt; ein manueller Neuversuch erfordert einen neuen Zielpfad (neuer UUIDv7-Suffix) und damit einen neuen Schlüssel, protokolliert mit Bezug auf die fehlgeschlagene Operation (extra: retry_of_operation_id im JSON verify_result bzw. als eigene Spalte in v1.1).

### 3.2 Zielpfad und Dateiname

sanitized_filename = sanitize(original_filename) plus "_" plus UUIDv7 (erst nach der Schlüsselprüfung aus 3.1 vergeben) plus Originalendung (Endung nur, wenn sie aus dem Zeichenvorrat a bis z, 0 bis 9 besteht und maximal 8 Zeichen hat, sonst ".bin").

sanitize: Transliteration nach ASCII (ä zu ae usw.), erlaubt sind a bis z, A bis Z, 0 bis 9, Punkt, Bindestrich, Unterstrich; alle anderen Zeichen zu Unterstrich; keine Pfadtrenner; keine führenden Punkte; Mehrfach-Unterstriche reduzieren; Kürzung auf 120 Zeichen Nutzanteil; Gesamtlänge maximal 160 Zeichen (Spalte VARCHAR(160)).

target_path = allowed_write_prefix plus sanitized_filename. Unterordner im Posteingang werden in v1.0 nicht angesteuert (ob sie existieren, ist VERMUTET).

### 3.3 Statusmaschine

Verbindliche Statuswerte sind die von App\Core\Enums\WriteOperationStatus (Änderungsvermerk 12.09.2026, Abgleich mit dem Code): pending, prechecked, sent, unknown, verified, failed, rejected. mayHaveReachedRemote() gilt für sent, unknown, verified (kein Rücksprung, kein zweites PUT); isFinal() gilt für verified, failed, rejected. Die Konzeptnamen der ersten Fassung (queued, precheck, verifying, succeeded, skipped_exists, failed_verify) sind in der Spalte Konzeptname zur Nachverfolgung aufgeführt; API und UI zeigen ausschließlich die Code-Werte.

| Von | Nach (Code) | Konzeptname | Auslöser | Nebenwirkung |
|---|---|---|---|---|
| (neu) | pending | queued | WriteIntent akzeptiert, Audit write.queued (Aktionsname beibehalten). Bei gesperrtem Flag, fehlender Capability oder fehlender Freigabe (API-Key) bleibt der Datensatz pending mit rejected_reason bzw. outcome denied oder pending_approval im precheck_result; Fortsetzung nur durch menschliche Freigabe im Hub (Admin-Oberfläche oder Kommando hub:write:approve, Audit write.approved) | Inhalt wird für die Fortsetzung referenzierbar abgelegt (source_storage_key), sonst kein Fortsetzungspfad |
| pending | prechecked | precheck | Job startet, precheck_attempts + 1, PROPFIND Depth 0 auf target_path liefert 404 | |
| pending | rejected | skipped_exists | PROPFIND 207 (Ressource existiert) mit abweichendem Inhalt (GET-Hash ungleich content_hash) oder Guard verletzt (Präfix, Größe, Dateiname, Connection nicht aktiv) | precheck_result.conflict = write_target_exists bzw. rejected_reason, Audit, keine Livesystem-Wirkung, kein PUT |
| pending | verified | succeeded | PROPFIND 207 und GET-Hash = content_hash (identische Datei liegt bereits im Posteingang, z. B. nach Race mit Scanner) | precheck_result.exists_identical = true, kein PUT |
| pending | failed | failed | PROPFIND liefert weder 207 noch 404 nach 3 Versuchen, oder 401/403 | Breaker-Zähler, DLQ bei Netzfehler, Audit |
| prechecked | sent | sent | unmittelbar PUT mit If-None-Match: *; put_attempts = 1; sent_at gesetzt. Status wird VOR dem Senden persistiert | |
| sent | verified | verifying, succeeded | PUT antwortet 201 oder 204; Verifikation per PROPFIND (getcontentlength = size_bytes) und bei verify_with_hash GET-Hash = content_hash im selben Lauf | verified_at; documents-Zeile mit origin hub_upload und write_operation_id auf der gekoppelten Lese-Connection (paired_read_connection_id), damit der nächste Posteingang-Scan dieselbe Zeile aktualisiert statt ein Duplikat anzulegen; Audit; Webhook-Ereignis geplant |
| sent | rejected | skipped_exists | PUT antwortet 412 | precheck_result.conflict = write_target_exists, rejected_reason = precondition_failed (Race mit Scanner) |
| sent | failed | failed, failed_verify | PUT antwortet 4xx außer 412, oder 5xx mit verwertbarer Antwort; oder Verifikation schlägt fehl (Länge oder Hash abweichend nach maximal 3 Versuchen) | Kein Retry-PUT. last_error und verify_result nennen den Grund (verify_failed). Konflikt write_verify_failed, manuelle Bereinigung im DMS innerhalb von 7 Tagen (Papierkorb) |
| sent | unknown | unknown | Netzwerkfehler ohne verwertbare Antwort (Timeout, Reset) | Ausschließlich PROPFIND als Folgeschritt: ResolveUnknownWriteOperationJob wird mit Verzögerung IMMOWARE_WRITE_UNKNOWN_PROPFIND_INTERVAL_SECONDS eingeplant |
| unknown | verified | verifying, succeeded | PROPFIND findet die Ressource, Verifikation wie oben | |
| unknown | failed | failed | PROPFIND findet die Ressource nach IMMOWARE_WRITE_UNKNOWN_PROPFIND_ATTEMPTS Prüfungen im Abstand IMMOWARE_WRITE_UNKNOWN_PROPFIND_INTERVAL_SECONDS nicht | Konflikt write_unknown_unresolved, manuelle Entscheidung, kein automatisches zweites PUT |

Datenbank-Trigger BEFORE INSERT und BEFORE UPDATE auf write_operations (MariaDB/MySQL, docs/architecture/03-mariadb-triggers.sql): put_attempts darf 1 nicht überschreiten und nicht verringert werden; Rückwechsel von sent, unknown oder verified auf pending oder prechecked ist verboten; operation darf nur webdav_create sein. Auf SQLite (Tests) gilt dieselbe Regel über WriteOperation::saving (WriteBlockedException).

### 3.4 Neustartverhalten

Beim Start des Hubs und über das Kommando hub:write:resume (Scheduler) werden alle write_operations mit status in (sent, unknown) ausschließlich über PROPFIND weitergeführt (PosteingangUploadService::resume). Ein erneutes PUT ist codeseitig durch put_attempts und den Trigger ausgeschlossen. Operationen in status prechecked werden auf pending zurückgesetzt (kein PUT hat stattgefunden, weil status sent vor dem Senden persistiert wird). Änderungsvermerk 12.09.2026: Statuswerte des Codes; Horizon wird nicht eingesetzt (queue:work).

### 3.5 Sichtbarkeit und Protokoll

Jede Statusänderung erzeugt einen Eintrag in audit_logs (action write.<status>, before_json, after_json, actor). Der Auditlog ist append-only mit Hash-Kette. Die Hub-UI zeigt je Operation Status, Zielpfad, Hash, Zeitpunkte, http_status, verify_result und verknüpfte Konflikte. Konsumenten erhalten den Zustand einer Operation nur über den Idempotenzschlüssel, nie durch einen erneuten Schreibaufruf.

## 4. Kein DELETE-Writeback, kein Overwrite: Begründung und Ersatzprozess

Belegt ist, dass Löschungen und Überschreibungen per WebDAV sofort im Livesystem wirken und der Papierkorb nach 7 Tagen geleert wird (VERIFIZIERT bzw. DOKUMENTIERT). Ein automatisierter Löschpfad hätte damit unmittelbares Datenverlustpotenzial ohne Wiederherstellung nach einer Woche. Der Hub verzichtet daher vollständig darauf:

- Fehlerhafte Uploads (failed mit verify_failed, fachlich falsche Datei) werden als Konflikt gemeldet. Ein berechtigter Mitarbeiter entfernt die Datei manuell im DMS innerhalb von 7 Tagen und schließt den Konflikt mit resolution_note; der nächste Lesezyklus bestätigt das Fehlen der Ressource über missing_since.
- Ersatzversionen werden nie durch Überschreiben, sondern als neue Datei mit neuem UUIDv7-Suffix eingestellt. Die Zuordnung zur ersetzten Datei liegt im Hub (documents.write_operation_id, conflicts.resolution_note).
- Soft Delete im Hub-Spiegel (documents.deleted_at) ist ausschließlich Ergebnis der Lese-Synchronisation und löst niemals eine Aktion gegen Immoware24 aus.
- Änderungswünsche an Kontakten, Terminen und Stammdaten laufen über conflicts vom Typ proposed_change und werden manuell in Immoware24 umgesetzt.

## 5. Freigabevoraussetzungen vor erster Aktivierung (Checkliste Geschäftsführung)

| Nr. | Voraussetzung | Kennzeichnung | Nachweis im Repository |
|---|---|---|---|
| W1 | Schriftliche Bestätigung des Immoware24-Supports, dass ein automatisierter WebDAV-Upload durch eine Serveranwendung zulässig ist | WAITING_FOR_VENDOR_ACCESS | Schreiben als Dokument, write_approval_document_id |
| W2 | DAV-Modul gebucht, Kosten freigegeben | WAITING_FOR_VENDOR_ACCESS | Auftragsbestätigung |
| W3 | Eigener technischer Schreibnutzer mit kleinster tragfähiger Rolle, Dateifreigabe möglichst nur Posteingang | zu verifizieren am eigenen Mandanten | Screenshot config.dav.immoware24.de, Verwaltung, ohne Zugangsdaten |
| W4 | Probe erfolgreich: Auth-Schema, PROPFIND, If-None-Match liefert 412 beim zweiten PUT einer Testdatei, Testdatei manuell durch Mitarbeiter entfernt | zu verifizieren am eigenen Mandanten | probe_result, test_protocol der Capability webdav.create |
| W5 | Testlauf mit IMMOWARE_WRITE_DRY_RUN = true, danach Testlauf mit drei Testdokumenten, Verifikation per Hash, manuelle Bereinigung im DMS | Phase 2 | Protokoll |
| W6 | Prüfung der AGB zur automatisierten Nutzung durch Rechtsanwalt | zu verifizieren | Stellungnahme |
| W7 | Rotationsprozess für das Freigabe-Passwort dokumentiert (Reset betrifft alle Synchronisationen des Nutzers, VERIFIZIERT) | Phase 2 | Betriebshandbuch |
| W8 | Zwei Personen (admin als erste, release als zweite Person) setzen write_enabled, Freigabeprotokoll der Geschäftsführung liegt vor; Hinweis auf fehlende Ordnerbeschränkung, falls W3 dies ergibt | Phase 2 | immoware_connections, audit_logs |

Bis alle Punkte erfüllt sind, bleibt IMMOWARE_WRITE_ENABLED = false und jede Connection auf write_enabled = 0.

## 6. Offene Punkte

| Nr. | Punkt | Kennzeichnung |
|---|---|---|
| S1 | Zulässigkeit automatisierter Uploads laut Immoware24 | WAITING_FOR_VENDOR_ACCESS |
| S2 | Verhalten des Servers auf If-None-Match: * (412 oder Ignorieren) | zu verifizieren am eigenen Mandanten; bei Ignorieren bleibt der Precheck die einzige Overwrite-Sperre, Zeitfenster zwischen PROPFIND und PUT wird im Freigabeprotokoll benannt |
| S3 | Maximale Dateigröße und erlaubte Dateitypen im Posteingang | NICHT VERFÜGBAR, Default 25 MB |
| S4 | Automatische Zuordnung hochgeladener Dateien zu Objekten durch OCR/KI | NICHT VERFÜGBAR, nur Dateinamenskonvention |
| S5 | Beschränkbarkeit einer Dateifreigabe auf den Posteingang | VERMUTET |
| S6 | Unterordner im Posteingang | VERMUTET, in v1.0 nicht genutzt |
| S7 | Ob PUT mit Content-Type oder chunked Transfer vom Server akzeptiert wird | zu verifizieren am eigenen Mandanten (Probe) |
