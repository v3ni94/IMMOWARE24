# 02 Backup und Restore

Stand: 12.09.2026
Grundlage: `docs/architecture/01-architecture-decision.md` Abschnitt 7 und 8, `docs/implementation-plan.md` AP 7.4 und AP 7.5.

## 1. Ergebnis

Täglich ein verschlüsseltes Vollbackup der MariaDB, täglich ein getrennter Export der Hub-eigenen Entscheidungen, wöchentlich die Audit-Kettenwurzel an unveränderlichen Speicher, Binlog kontinuierlich für Point-in-Time-Recovery. Quartalsweise Wiederherstellungstest in eine getrennte Prüfdatenbank. Umsetzung: `deploy/scripts/backup.sh` und `deploy/scripts/restore-test.sh`.

## 2. Was gesichert wird

| Bestand | Warum | Rhythmus | Aufbewahrung |
|---|---|---|---|
| MariaDB vollständig (`mariadb-dump --single-transaction`, mit Binlog-Position) | Gesamter Spiegel, Payload-Archiv (Inline-Anteil), Auditlog | täglich 03:00 UTC | 35 Tage lokal und offsite |
| Binlog (`--log-bin`, ROW, 7 Tage in `compose.yaml`) | Point-in-Time-Recovery zwischen zwei Vollbackups | kontinuierlich | 7 Tage |
| Entscheidungstabellen: conflicts, contact_merges, external_mappings, import_formats, capabilities, users, api_keys, webhook_endpoints, immoware_connections, immoware_technical_users, proposed_changes | Nicht aus Payloads rekonstruierbar (Architekturentscheidung Abschnitt 7). Die drei letztgenannten Tabellen ergänzen die Liste des Konzepts, weil ohne Connections und Technische Nutzer kein Replay möglich ist (Änderungsvermerk 12.09.2026) | täglich | 400 Tage |
| `audit_anchors` | Kettenwurzel des append-only Auditlogs | wöchentlich Sonntag | unbegrenzt, Object Lock offsite |
| `storage/app` (Importdateien, archivierte Payloads über 64 KB) | Rohdaten vor Interpretation | täglich, falls `BACKUP_STORAGE_DIR` gesetzt | 35 Tage |
| `.env` und GPG-Schlüssel | Ohne `APP_KEY` sind verschlüsselte Spalten wertlos | bei jeder Änderung manuell in den Secret-Store | dauerhaft |

Nicht gesichert wird der Redis-Inhalt (Queue, Cache, Locks, Metriken). Nach einem Redis-Verlust laufen alle Läufe über den Scheduler neu an; `write_operations` in `sent` oder `unknown` werden ausschließlich über PROPFIND weitergeführt (CLAUDE.md Regel 5, Architekturentscheidung Abschnitt 8).

## 3. Verschlüsselung und Ablage

- Pipeline `mariadb-dump | zstd | gpg --encrypt --recipient $BACKUP_GPG_RECIPIENT`, keine Klartextdatei auf Platte.
- Der private GPG-Schlüssel liegt nicht auf dem Server, sondern offline bei der Geschäftsführung und einer benannten Vertretung. Der öffentliche Schlüssel ist im Schlüsselbund des Betriebsnutzers importiert.
- Zugangsdaten werden über eine temporäre `--defaults-extra-file` übergeben, nie auf der Kommandozeile.
- `BACKUP_OFFSITE_CMD` kopiert jede Datei plus `.sha256` an einen S3-kompatiblen EU-Speicher mit Versionierung; für `audit-anchors` Object Lock (Compliance-Modus).
- Aufruf per Cron oder systemd-Timer als Nutzer `immoware`, Umgebung aus `/etc/immoware-hub/backup.env` (0600). Docker: `docker compose exec -T mariadb` ist nicht nötig, das Skript verbindet sich über den veröffentlichten Port oder läuft in einem Sidecar mit `mariadb-client`.

Cron-Beispiel:

```
0 3 * * *  immoware  . /etc/immoware-hub/backup.env && /var/www/immoware-hub/current/deploy/scripts/backup.sh >> /var/log/immoware-hub/backup.log 2>&1
```

## 4. Restore-Szenarien

| Szenario | Vorgehen |
|---|---|
| Hub-Datenbank verloren | Vollrestore (Abschnitt 5), danach `php artisan migrate --force`, `hub:doctor`, Full Reconcile über `hub:sync:dispatch all --mode=full`. Entscheidungen aus dem Entscheidungsexport prüfen, falls das Vollbackup älter ist |
| Einzelne Tabelle beschädigt | Restore in Prüfdatenbank, Tabelle per `INSERT ... SELECT` übernehmen, nie `TRUNCATE` auf Spiegeldaten |
| Zeitpunkt vor einem Fehler nötig | Vollbackup einspielen, dann `mariadb-binlog --start-position=<aus --master-data> --stop-datetime=<Zeitpunkt>` einspielen |
| Mapper-Fehler | Kein Restore, sondern Replay aus `external_payloads` mit neuer `sync_version` (Architekturentscheidung Abschnitt 8). Hinweis: das dort genannte Kommando `hub:replay --from payload` ist im Code am 12.09.2026 noch nicht vorhanden, siehe Abschnitt 7 |
| Fehlerhafter Upload in den Posteingang | Kein Restore und kein DELETE. Manuelle Bereinigung im DMS durch berechtigten Mitarbeiter innerhalb von 7 Tagen, Konflikteintrag, Audit |

## 5. Vollrestore (Produktion)

Nur mit Freigabe der Geschäftsführung, Vier-Augen-Prinzip, Protokoll.

1. Anwendung anhalten: `php artisan down`, Worker und Scheduler stoppen (`systemctl stop immoware-hub-worker@*` und `immoware-hub-scheduler.timer` oder `docker compose stop worker scheduler`).
2. Aktuellen Zustand sichern, auch wenn er beschädigt ist: `backup.sh` mit `BACKUP_DIR=/var/backups/immoware-hub/pre-restore`.
3. Prüfsumme kontrollieren: `sha256sum -c <datei>.sha256`.
4. Leere Datenbank: `CREATE DATABASE immoware_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` (bestehende vorher umbenennen, nicht löschen).
5. Einspielen: `gpg --decrypt <datei>.sql.zst.gpg | zstd -d | mariadb immoware_hub`.
6. Optional Binlog bis zum Zielzeitpunkt nachziehen (Abschnitt 4).
7. `php artisan migrate --force`, `php artisan audit:verify`, `php artisan hub:doctor`.
8. Redis leeren ist nicht nötig, aber Locks prüfen: `redis-cli --scan --pattern 'hub:sync:lock*'`. Alte Locks laufen per TTL aus.
9. Worker, Scheduler starten, `php artisan up`, Health-Check, Full Sync anstoßen, Datenalter im Admin-Dashboard beobachten.
10. Protokoll in Abschnitt 6 ergänzen.

## 6. Wiederherstellungstest (quartalsweise)

`deploy/scripts/restore-test.sh <backupdatei>` spielt ein Backup in `immoware_hub_restoretest` auf einer Prüfinstanz ein (Datenbankname muss `restoretest` enthalten, Schutz vor Verwechslung), prüft Prüfsumme, Tabellenzahl, Zeilenzahlen der Kerntabellen, letzte Migration und optional `migrate:status` sowie `audit:verify` gegen die Prüfdatenbank. Anschließend wird die Prüfdatenbank gelöscht (`KEEP_RESTORE_DB=1` behält sie).

Zusätzlich getrennt zu testen (AP 7.5): Replay-Restore aus Payloads, sobald das Kommando existiert.

Protokoll:

| Datum | Backupdatei | Typ | Tabellen | Dauer | Ergebnis | Tester |
|---|---|---|---|---|---|---|
| noch kein Lauf | | Vollrestore | | | | |
| noch kein Lauf | | Replay-Restore | | | | |

## 7. Offene Punkte

- `hub:replay --from payload` (Plan AP 3.9, AP 7.5) ist im Code nicht vorhanden. Bis dahin ist nur der Vollrestore testbar; der Replay-Restore ist als offen zu führen. Zuständig: Entwicklung Modul Sync.
- Löschfristen personenbezogener Daten in Backups (Backups enthalten Kontaktdaten) mit Rechtsanwalt abstimmen; Aufbewahrung 35 Tage ist ein Vorschlag, Aufbewahrung buchhaltungsrelevanter Daten (10 Jahre) betrifft die Quellsysteme, nicht den Spiegel, mit Steuerberater klären.
- Offsite-Ziel (Anbieter, Region, Object Lock) durch Geschäftsführung festlegen.
