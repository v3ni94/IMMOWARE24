# 03 Monitoring und Alarmierung

Stand: 12.09.2026
Grundlage: `docs/implementation-plan.md` AP 7.6, `docs/immoware/07-sync-strategy.md` Abschnitt 6, `app/Modules/Api/Health/HealthService.php`, `app/Modules/Sync/Services/SyncMetrics.php`.

## 1. Ergebnis

Vier Alarme sind Pflicht vor Go-live: stale Daten, DLQ größer 0, Anstieg von 429-Antworten, Circuit Breaker offen. Datenquelle sind die Health-Endpunkte (extern pollbar) und die Metriken aus `SyncMetrics` (Cache, für Admin und Health). Empfänger ist der Betrieb (operator), bei Breaker offen zusätzlich admin, bei Schreibpfad-Störungen release und Geschäftsführung.

## 2. Health-Endpunkte

Alle unter `https://immoware.muellerhv.de`, ohne Auth nur `status`, mit API-Key und Scope `admin` zusätzlich `details` und `checked_at`. `Cache-Control: no-store`.

| Endpunkt | Inhalt | HTTP |
|---|---|---|
| `/up` | Laravel-Basisprüfung (Framework bootet) | 200 |
| `/health` | Aggregat aus database, queue, immoware; `status` ok, degraded oder down | 200 bei ok und degraded, 503 bei down |
| `/health/database` | PDO-Verbindung, `select 1`, Tabelle `migrations` vorhanden, Latenz | 200 oder 503 |
| `/health/queue` | Tiefe der Tabelle `jobs`, ältester Job, `failed_jobs`, offene `dlq_items` | 200 oder 503 |
| `/health/immoware` | Je aktiver Connection: `last_health_ok`, `degraded_reason`, je Entität `last_success_at`, `data_age_seconds`, `threshold_seconds`, `stale` | 200 oder 503 |

Statuslogik (Code, nicht Konzept): `queue` ist degraded bei Tiefe über `HUB_HEALTH_QUEUE_DEPTH_WARNING` (Standard 1000) oder `dlq_open > 0`. `immoware` ist down, sobald eine aktive Connection keinen Sync-Stand hat oder eine Entität stale ist (Schwellen `config/hub/sync.php` `stale_after_seconds`: contact 7200 s, document 7200 s, calendar_event 28800 s, Standard 86400 s), degraded bei `status = degraded` oder `last_health_ok = false`.

Empfohlene externe Prüfung (Uptime-Monitor oder Prometheus Blackbox): `GET /health` alle 60 s, Alarm bei 503 über 5 Minuten oder bei Nichterreichbarkeit über 3 Minuten. Zusätzlich `GET /health` mit Admin-Key alle 5 Minuten zur Auswertung der Details.

## 3. Metriken

`SyncMetrics` hält Zähler und Gauges im Cache (Redis, TTL 7 Tage, Präfix `hub:sync:metrics`) mit Labels je Connection und Entität:

| Metrik | Typ | Bedeutung |
|---|---|---|
| `sync_duration_sum_ms`, `_count`, `_max_ms` | Zähler | Dauer der Läufe |
| `sync_records` | Zähler | verarbeitete Datensätze |
| `sync_errors` | Zähler | Fehler je Lauf |
| `remote_requests` | Zähler | Anfragen an den DAV-Adapter |
| `429_count` | Zähler | Rate-Limit-Antworten des DAV-Adapters |
| `queue_depth` | Gauge | Queue-Tiefe |
| `failed_jobs` | Gauge | fehlgeschlagene Jobs |
| `skipped_locked` | Zähler | wegen Lock übersprungene Läufe |

Weitere Betriebskennzahlen aus der Datenbank: `remote_requests` (Tabelle, Statuscodes, Latenzen, Aufbewahrung 90 Tage), `sync_runs`, `dlq_items`, `write_operations` je Status, `conflicts` offen, `audit_logs` letzte Verankerung.

Queue-Tiefe bei `QUEUE_CONNECTION=redis` (Änderungsvermerk 12.09.2026): Der Laravel-Redis-Treiber hält je Queue eine Liste `<prefix>queues:<name>` (Präfix aus `config/database.php`, Standard `<APP_NAME>-database-`), dazu `queues:<name>:delayed` und `queues:<name>:reserved` als Sorted Sets. Abfrage: `redis-cli -a "$REDIS_PASSWORD" --no-auth-warning LLEN "<prefix>queues:sync"` für jede der Queues `high`, `default`, `sync`, `write`, `documents`, `low`, bzw. `ZCARD` für `:delayed` und `:reserved`. Alternativ `php artisan queue:monitor redis:high,redis:default,redis:sync,redis:write,redis:documents,redis:low --max=5000`: gibt die Tiefe je Queue aus und feuert bei Überschreitung das Event `QueueBusy`, endet aber immer mit Exit-Code 0 und eignet sich deshalb nur als Scheduler-Kommando für Alarm A5, nicht als Healthcheck.

Ein Prometheus-Exporter ist nicht Teil des Repositories. Bis dahin: Node-Exporter, mysqld-Exporter, redis-Exporter auf dem Host und die Health-Details per Skript in das Monitoring einspeisen.

## 4. Alarme

| Alarm | Bedingung | Schwere | Empfänger | Erste Maßnahme (Runbook) |
|---|---|---|---|---|
| A1 stale | `/health/immoware` liefert `stale = true` für eine Entität, oder `/health` antwortet 503 länger als 10 Minuten | hoch | operator | 04-runbook.md Störfall 1 (401) oder 4 (Worker hängt) |
| A2 DLQ | `dlq_open > 0` in `/health/queue` | mittel, hoch ab 10 Einträgen oder bei Queue `write` | operator, bei `write` zusätzlich release | 04-runbook.md Störfall 5 |
| A3 429-Anstieg | `429_count` steigt um mehr als 10 in 15 Minuten oder Anteil 429 an `remote_requests` über 5 Prozent in 1 Stunde | mittel | operator, admin | Intervalle verlängern (`HUB_SYNC_CRON_*`), `IMMOWARE_RATE_LIMIT_RPS` senken (Standard 2), Supportanfrage Immoware24 zu Limits (NICHT VERFÜGBAR) |
| A4 Circuit open | Breaker-Zustand `open` für eine Connection (Cache-Schlüssel des `CircuitBreaker`, sichtbar im Admin-Dashboard) oder `last_health_ok = false` über 10 Minuten; Breaker öffnet nach 5 Fehlern in 120 s, bleibt 600 s offen, ab drittem Zyklus 3600 s (`config/hub/connector.php`) | hoch | operator, admin | 04-runbook.md Störfall 1, bei 401 Passwort-Rotation Störfall 2 |
| A5 Queue-Rückstau | `queue_depth` über 1000 oder ältester Job älter als 30 Minuten | mittel | operator | Störfall 4 |
| A6 Scheduler stumm | kein `sync_runs`-Eintrag seit 20 Minuten trotz aktiver Connection | hoch | operator | Scheduler-Prozess prüfen (`schedule:work` als Container oder systemd-Dienst `immoware-hub-scheduler.service`) |
| A7 Backup | `backup.sh` Exit-Code ungleich 0 oder keine neue Datei in `daily/` seit 26 Stunden | hoch | operator, Geschäftsführung wöchentlich im Bericht | 02-backup-restore.md |
| A8 Schreibpfad | `write_operations` in `unknown` älter als 20 Minuten oder `failed_verify` größer 0 | hoch | release, Geschäftsführung | Kein zweites PUT. Runbook Störfall 6 |
| A9 Audit | `audit:verify` (täglich 02:00) meldet Kettenbruch | kritisch | admin, Geschäftsführung | Sofort einfrieren, Backups sichern, Rechtsanwalt bei Verdacht auf Manipulation |
| A10 Zertifikat, Platte | TLS-Ablauf unter 14 Tagen, Platte über 80 Prozent | mittel | operator | certbot renew, Payload-Prune prüfen (`hub:payloads:prune --dry-run`) |

Stille Zeiten gibt es nicht; die Sync-Intervalle laufen rund um die Uhr. Alarmkanal: E-Mail an die Betriebsadresse und Push (Anbieter durch Geschäftsführung festzulegen). Keine Immoware24-Zugangsdaten oder Kontaktdaten in Alarmtexten.

## 5. Abweichung Code gegen Konzept (Änderungsvermerk 12.09.2026)

`HealthService::queue()` misst die Queue-Tiefe über die Tabelle `jobs`. Produktion nutzt `QUEUE_CONNECTION=redis`, dort ist die Tabelle leer und `depth` sowie `oldest_job_age_seconds` sind immer 0. `failed_jobs` (Datenbank) und `dlq_open` sind korrekt. Einschränkung für Betrieb und Deploy: `/health/queue` liefert 200, solange keine DLQ-Einträge und keine `failed_jobs`-Schwelle greifen, auch wenn Redis-Queues voll sind oder kein Worker läuft. Bis der Health-Check den Redis-Treiber abfragt, liefert der Alarm A5 seine Daten aus `queue:monitor` und `LLEN` (Abschnitt 3). Zuständig: Entwicklung Modul Api und Sync. Datenintegrität ist nicht betroffen, nur die Sichtbarkeit.

Worker-Healthcheck in `compose.yaml` (Änderungsvermerk 12.09.2026): Der frühere Healthcheck `queue:monitor` war ohne Aussagekraft (Exit immer 0, prüft nicht den Worker-Prozess). Zielbild ist ein Heartbeat: Das Scheduler-Kommando `hub:worker:heartbeat` stellt minütlich einen kleinen Job auf die Queue `high`; der Job schreibt den aktuellen Zeitstempel in die Datei `storage/framework/worker-heartbeat` (und optional in den Cache-Schlüssel `hub:worker:heartbeat`). Der Healthcheck des Worker-Containers prüft das Alter der Datei und gilt ab 180 Sekunden ohne neuen Zeitstempel als unhealthy, wodurch `restart: unless-stopped` einen hängenden Worker neu startet. Der Scheduler-Container erhält analog eine Prüfung auf `storage/framework/schedule-heartbeat`, geschrieben von `schedule:work` über einen minütlichen Scheduler-Eintrag. Stand Abschlusslauf 12.09.2026: umgesetzt im Modul Sync. `hub:worker:heartbeat` läuft minütlich im Scheduler (`routes/console.php`), schreibt `storage/framework/scheduler-heartbeat` direkt und stellt `WorkerHeartbeatJob` (Queue `high`, ein Versuch, eindeutig für 120 Sekunden) ein, der `storage/framework/worker-heartbeat` schreibt; beide Zeitstempel liegen zusätzlich im Cache unter `hub:heartbeat:<name>`. Die Healthchecks in `compose.yaml` rufen `php artisan hub:heartbeat:check worker|scheduler --max-age=180` (Exit 1 ohne oder mit zu altem Lebenszeichen). Voraussetzung ist ein gemeinsames `storage`-Volume von Scheduler und Worker. Alarm A6 (Scheduler stumm) und A5 bleiben zusätzliche Kontrollen.

## 6. Logs

- App, Worker, Scheduler schreiben nach stderr (`LOG_CHANNEL=stderr` im Container) beziehungsweise `storage/logs` (Host). JSON-Zeilen mit `correlation_id`; Anfragen tragen `X-Correlation-Id`.
- nginx Access-Log ohne Health-Aufrufe. Aufbewahrung 14 Tage, dann löschen (IP-Adressen sind personenbezogen).
- Keine Secrets in Logs (CLAUDE.md Regel 6); Stichprobe monatlich mit `grep -i "password\|authorization"` über die Logs des Vormonats.
