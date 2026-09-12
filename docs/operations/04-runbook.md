# 04 Runbook Störfälle

Stand: 12.09.2026
Grundlage: `docs/architecture/01-architecture-decision.md` Abschnitt 8 (Recovery), `docs/immoware/08-security.md` Abschnitt 2.3, `docs/immoware/07-sync-strategy.md` Abschnitt 6.

Grundsätze für jeden Störfall: Immoware24 bleibt Master. Nie per WebDAV löschen, verschieben oder überschreiben. Kein Hard Delete im Spiegel. Jede Maßnahme mit Datum, Person und Correlation-ID im Auditlog oder in diesem Dokument (Abschnitt 8) festhalten. Rückschaltung von `degraded` auf `active` und jede Änderung an Schreib-Flags nur durch die Rolle release im Vier-Augen-Prinzip.

## Störfall 1: 401 vom DAV-Adapter

Symptom: A4 Circuit open mit Grund 401, `/health/immoware` degraded oder down, `remote_requests` mit Status 401, Connection `status = error`.

Ursache (belegt): Ein Reset des Freigabe-Passworts im Konfigurationsportal wirkt auf alle Freigaben des Nutzers (VERIFIZIERT, 08-security.md 2.3). Weitere Ursachen: Freigabe durch admin gelöscht, DAV-Modul abgelaufen, Nutzer gesperrt.

Vorgehen:

1. Der Breaker hat bereits geöffnet (401 öffnet sofort, `CircuitBreaker`). Es laufen keine weiteren Anfragen. Nichts erzwingen.
2. Im Konfigurationsportal `https://config.dav.immoware24.de` mit dem technischen Nutzer prüfen: Freigabe vorhanden, Passwort zuletzt geändert wann. Mit einem Standard-Client (Finder, Explorer, DAVx5) einmal manuell verbinden, um Passwort gegen Freigabe zu isolieren.
3. Wenn das Passwort geändert wurde oder unbekannt ist: Störfall 2 (Rotation).
4. Wenn die Freigabe fehlt: Freigabe durch admin neu anlegen (Freigaben sind nicht editierbar, es entsteht ein neuer Freigabe-Link), Link in der Connection aktualisieren, Health-Check ausführen.
5. Nach erfolgreichem Health-Check: Connection über die Admin-UI durch release zurück auf `active` (Begründung Pflicht). Der Breaker schließt nach Half-Open automatisch.
6. Datenlage prüfen: `stale_since` verschwindet nach dem nächsten erfolgreichen Lauf; `write_operations` in `sent` oder `unknown` werden nur über PROPFIND weitergeführt, nie neu gesendet.

## Störfall 2: Rotation des Freigabe-Passworts

Anlass: planmäßig (halbjährlich, AP 7.8), nach Störfall 1, bei Personalwechsel, bei Verdacht auf Kompromittierung.

Vorgehen nach 08-security.md Abschnitt 2.3:

1. admin setzt alle Connections des betroffenen technischen Nutzers auf `paused`. Queue leert sich, keine Uploads.
2. Der technische Nutzer meldet sich am Konfigurationsportal an, Menü Meine Freigaben, Schaltfläche Passwort generieren. Klartext direkt in die Admin-UI des Hubs eintragen, nicht per E-Mail oder Chat übermitteln.
3. Hub speichert verschlüsselt in `immoware_technical_users.secret_encrypted`, setzt `secret_rotated_at`, führt je Connection einen Health-Check aus.
4. Erst bei Erfolg zurück auf `active`. Bei 401 bleibt `paused`, Schritt 2 wiederholen.
5. Audit-Eintrag `connection.secret_rotated` prüfen (ohne Klartext).
6. Bei Kompromittierungsverdacht zusätzlich: Freigabe löschen und neu anlegen (neuer Freigabe-Link), Geschäftsführung informieren, Prüfung durch Rechtsanwalt zu Meldepflichten (DSGVO Art. 33 als Einschätzung, Frist zu verifizieren).

Dauer: unter 30 Minuten. Datenverlust: keiner, Läufe setzen mit dem letzten Cursor fort.

## Störfall 3: Immoware-Update ändert Ordnerstruktur oder DAV-Verhalten

Symptom: Connection wechselt auf `degraded` mit `degraded_reason` Fingerprint oder Pfad-Allowlist; Läufe melden viele `unknown path` oder Soft-Deletes; `/health/immoware` degraded.

Vorgehen:

1. Nichts freigeben. Uploads pausieren automatisch, Lesen läuft mit stale-Kennzeichnung.
2. Soft-Delete-Schutz prüfen: Der Dokumentenspiegel setzt `deleted_at` erst nach zwei aufeinanderfolgenden Vollexporten (Mark-and-Sweep). Falls ein Lauf mehr als 10 Prozent der Dokumente als gelöscht markieren würde, ist der Lauf abzubrechen und die Ursache zu klären, bevor ein zweiter Lauf den Sweep bestätigt. Bis zur Klärung `HUB_SYNC_SCHEDULE_ENABLED=false` für Dokumente oder Connection `paused`.
3. Mit `php artisan hub:probe <connection>` neu messen: OPTIONS, PROPFIND Depth 0 und 1, DAV-Header, ETag-Stabilität. Ergebnis in `docs/immoware/10-test-report.md` protokollieren.
4. Ordnerstruktur mit Standard-Client sichten, Unterschiede dokumentieren (Release Notes im Support-Center prüfen, es gibt keinen Vorankündigungskanal).
5. Entwicklung passt Pfad-Allowlist, Mapping oder Strategie an; neue `sync_version` beziehungsweise Mapping-Version, alte Versionen bleiben. Tests grün, Deploy nach 01-deployment.md.
6. release schaltet die Connection mit Begründung zurück auf `active`. Full Sync anstoßen: `php artisan hub:sync:run <connection> document --mode=full`. Datenalter beobachten.
7. Betrifft die Änderung `IMMOWARE_WRITE_ALLOWED_PREFIX` (`/Posteingang/`): Schreibpfad bleibt aus, bis die Probe mit If-None-Match erneut bestanden ist und die Geschäftsführung die Freigabe erneuert hat.

## Störfall 4: Worker hängt oder Queue staut sich

Symptom: A5 Queue-Rückstau, `data_age_seconds` steigt, `skipped_locked` steigt, Worker-Container unhealthy, `supervisorctl status` zeigt RUNNING ohne Fortschritt.

Vorgehen:

1. Zustand ansehen: `php artisan queue:monitor redis:high,redis:default,redis:sync,redis:write,redis:documents,redis:low --max=5000`, `redis-cli --scan --pattern 'hub:sync:lock*'` (Locks mit TTL, Full-Sync-Lock bis 7200 s, Overlap 3600 s), `php artisan hub:doctor`.
2. Geordneter Neustart: `php artisan queue:restart` (Worker beenden nach dem laufenden Job). Docker: `docker compose restart worker`. systemd: `systemctl restart immoware-hub-worker@*`. supervisord: `supervisorctl restart immoware-hub-worker:*`.
3. Bleibt ein Job hängen: Prozess mit `SIGTERM` beenden, nach 30 Minuten (`--timeout=1800`) beendet Laravel ihn selbst. Der Job landet nach `$tries` in `failed_jobs` und `dlq_items`; Locks laufen per TTL aus, nie manuell `TRUNCATE` oder `FLUSHALL`.
4. Ursache eingrenzen: Speicher (`--memory=256`, Worker beendet sich bei Überschreitung und wird neu gestartet), langsame DAV-Antworten (`remote_requests` Latenz, Schwelle 5000 ms), Datenbank-Locks (`SHOW PROCESSLIST`).
5. Wenn Redis nicht erreichbar: Worker warten, Health `queue` down. Redis prüfen (`redis-cli ping`, appendonly-Datei, Speicher `maxmemory 256mb` mit `noeviction`, Alarm bei OOM im Redis-Log).
6. Nach Behebung: Datenalter je Entität prüft `php artisan hub:sync:stale`; der Scheduler holt inkrementelle Läufe nach. Kein manueller Full Sync ohne Grund.

Schreibpfad: Ein Neustart während eines PUT führt nie zu einem zweiten PUT; `write_operations` in `sent` oder `unknown` werden ausschließlich über PROPFIND aufgelöst (drei Versuche im Abstand von 300 s, dann `failed`).

## Störfall 5: DLQ abarbeiten

Symptom: A2, `dlq_open > 0`. Status je Eintrag: open, retrying, replayed, ignored, failed (`DlqStatus`).

Vorgehen:

1. Einträge sichten (Admin-UI Bereich Sync, im Aufbau; bis dahin lesend per SQL auf `dlq_items`: `job_class`, `queue`, `exception_class`, `trace` bis 8000 Zeichen, `payload_ref`). Correlation-ID notieren und die zugehörigen Logzeilen lesen.
2. Einordnen: transient (429, 5xx, Timeout, Breaker offen) oder fachlich (Mapping-Fehler, ungültige Datei, Guard-Verletzung).
3. Transient: Ursache beheben (Störfall 1 bis 4), dann Retry über `DlqService::retry()` (Admin-UI oder `php artisan tinker` mit angemeldetem operator; Audit `dlq.retry_requested`). Retry ist idempotent: Sync-Läufe sind cursor- und hashbasiert, Uploads laufen über `idempotency_key`.
4. Fachlich: Ticket an Entwicklung mit `dlq_items.id`; Eintrag bleibt `open`. Nach Fix und Deploy Retry.
5. Nicht mehr relevant (z. B. Datei inzwischen manuell importiert): `DlqService::ignore()` mit Begründung (Audit `dlq.ignored`). Ignorierte Einträge können nicht erneut ausgeführt werden.
6. DLQ-Einträge der Queue `write`: vor jedem Retry den Zustand der `write_operation` prüfen (`sent` oder `unknown` nie retryen, nur PROPFIND-Auflösung abwarten). Freigabe durch release.
7. Aufbewahrung 180 Tage (`hub.sync.dlq.retention_days`).

Ziel: DLQ am Ende jedes Arbeitstags leer oder jeder offene Eintrag mit Ticket versehen.

## Störfall 6: Fehlerhafter oder unklarer Upload in den Posteingang

Aus Architekturentscheidung Abschnitt 8 und AP 8.7: Kein automatisches Löschen. Konflikteintrag, manuelle Bereinigung im DMS durch berechtigten Mitarbeiter innerhalb von 7 Tagen (Papierkorb wird nach 7 Tagen geleert, DOKUMENTIERT), Dokumentation im Audit. `unknown` wird nur lesend aufgelöst. Bei wiederholtem `failed_verify`: Schreibpfad über `IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED=false` anhalten, Geschäftsführung informieren.

## Störfall 7: Ausfall Immoware24 oder DAV-Adapter

Lesen aus dem Spiegel mit stale-Kennzeichnung, Uploads bleiben `queued`, keine Datenänderung. Keine Maßnahme außer Beobachtung und Information der Fachbereiche über das Datenalter. Nach Wiederkehr schließt der Breaker über Half-Open, Läufe setzen fort.

## 8. Störfallprotokoll

| Datum | Störfall | Connection | Dauer | Maßnahmen | Correlation-IDs | Bearbeiter | Freigabe release |
|---|---|---|---|---|---|---|---|
| noch kein Eintrag | | | | | | | |

## 9. Offene Punkte

- Admin-UI für Connections (paused, active, Secret-Eingabe) und DLQ ist im Aufbau (README Status). Bis zur Fertigstellung laufen die Schritte über Services im `tinker` durch admin mit Protokoll; jede Aktion wird auditiert.
- Kommando für Retry und Ignore der DLQ auf der Konsole fehlt; Vorschlag `hub:dlq:list|retry|ignore` an Entwicklung Modul Sync.
- Halbjährliche Rotation (Störfall 2) in den Terminkalender der Geschäftsführung eintragen.
