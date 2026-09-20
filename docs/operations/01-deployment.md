# 01 Deployment

Stand: 12.09.2026
Gesellschaft: Hausverwaltung Müller GmbH (Betreiberin des Hubs)
Betriebsdomain: https://immoware.muellerhv.de

## 1. Ergebnis

Der Hub wird als ein Deployment (modularer Monolith, ADR 0001) betrieben. Zwei gleichwertige Betriebsvarianten sind im Repository hinterlegt:

| Variante | Dateien | Empfehlung |
|---|---|---|
| A Docker Compose | `Dockerfile`, `compose.yaml`, `docker/**` | Standard für Staging und Produktion, weil Versionen von PHP, MariaDB und Redis reproduzierbar gepinnt sind |
| B Host-Installation | `deploy/nginx/**`, `deploy/supervisor/**`, `deploy/systemd/**`, `deploy/scripts/deploy.sh` | Fallback, falls der Hoster keine Container zulässt |

Beide Varianten laufen mit denselben Prozessrollen: `app` (php-fpm), `web` (nginx), `worker` (Queue), `scheduler`, `mariadb`, `redis`. Secrets liegen ausschließlich in der `.env` auf dem Server beziehungsweise im Secret-Store, nie im Repository.

## 2. Voraussetzungen

- Server in der EU, bevorzugt Deutschland (Architekturentscheidung Abschnitt 7). AV-Vertrag mit dem Hoster liegt vor.
- DNS-Eintrag `immoware.muellerhv.de` auf die öffentliche IP des Servers, TLS-Zertifikat (Let's Encrypt oder Zertifikat des Hosters).
- Variante A: Docker Engine 25 oder neuer mit Compose v2. Variante B: PHP 8.4 (fpm, cli) mit pdo_mysql, redis, intl, zip, opcache, mbstring, openssl, bcmath; Composer 2; nginx; MariaDB 11.x; Redis 7; supervisord oder systemd.
- Ausgehender HTTPS-Zugriff des Servers auf den DAV-Endpunkt des Immoware24-Mandanten (Freigabe-Link, siehe `docs/immoware/04-authentication.md`). Kein weiterer ausgehender Zugriff nötig.
- Ein dedizierter Betriebsnutzer `immoware` ohne Shell-Login für Cron oder sudo-Rechte, nur `systemctl reload php8.4-fpm` per sudoers (Variante B).

## 3. Variante A: Docker Compose

### 3.1 Aufbau

```
docker compose up -d --build                                 Build und Start aller Services
docker compose run --rm app php artisan migrate --force      Migrationen (bewusst nicht im Entrypoint)
docker compose exec app php artisan hub:doctor               Konfiguration, Flags, BootGuard, Queue, DB, Redis
docker compose exec app php artisan hub:user:create          Erster Nutzer (2FA-Pflicht)
```

Der Container `web` bindet standardmäßig nur `127.0.0.1:8080`. Ein vorgelagerter Reverse Proxy auf dem Host terminiert TLS für `immoware.muellerhv.de` und reicht HTTP an `127.0.0.1:8080` weiter. Er setzt `X-Forwarded-Proto: https` und `X-Forwarded-For`. HSTS setzt der Proxy, die übrigen Sicherheitsheader setzt nginx im Container.

Die `.env` neben `compose.yaml` muss mindestens enthalten: `APP_KEY`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD`, `HUB_ENCRYPTION_KEY_PROVIDER`, `HUB_ENCRYPTION_KEY_ID`. `compose.yaml` bricht ohne die Pflichtwerte ab (`${VAR:?...}`). Host, Port und Treiber für Datenbank und Redis setzt `compose.yaml` fest.

### 3.2 Image

Multi-Stage-Build auf `php:8.4-fpm-alpine`: Stufe `vendor` führt `composer install --no-dev` aus, Stufe `runtime` enthält nur Laufzeitbibliotheken, die Erweiterungen pdo_mysql, redis (pecl, Version gepinnt), intl, zip, opcache, pcntl, bcmath und läuft als `www-data` (non-root). `opcache.validate_timestamps=0`: Codeänderungen erfordern einen neuen Build und Neustart, das ist beabsichtigt.

Der Entrypoint baut Config-, Routen-, View- und Event-Cache nur für `php-fpm` auf. `migrate` läuft nie automatisch, damit ein Neustart nie unbeabsichtigt das Schema ändert (Datenintegrität).

### 3.3 Update

```
git pull                                                     oder Tag auschecken
docker compose build app
docker compose run --rm app php artisan hub:doctor
docker compose run --rm app php artisan migrate --force
docker compose up -d app worker scheduler                    Neustart mit neuem Image
docker compose exec app php artisan queue:restart
curl -fsS https://immoware.muellerhv.de/health
```

Der Worker beendet sich nach `--max-time=3600` und wird durch `restart: unless-stopped` neu gestartet; `queue:restart` beschleunigt die Übernahme des neuen Codes. Laufende Jobs bekommen bis `--timeout=1800` Zeit, das entspricht dem längsten zulässigen Sync-Lauf (`hub.sync.locks.overlap_ttl_seconds`).

## 4. Variante B: Host-Installation

### 4.1 Verzeichnisse

```
/var/www/immoware-hub/releases/<zeitstempel>   Code je Release
/var/www/immoware-hub/shared/.env               Konfiguration, Rechte 0600, Eigentümer immoware
/var/www/immoware-hub/shared/storage            persistent
/var/www/immoware-hub/current                   Symlink auf das aktive Release
/etc/immoware-hub/worker.env                    optionale Umgebung für systemd-Units
/var/log/immoware-hub/                          Worker-Logs (supervisord)
```

### 4.2 Einrichtung

Für einen frischen dedizierten Server erledigt `deploy/scripts/server-bootstrap.sh` die Schritte 1 bis 5 dieses Abschnitts einschließlich Systemhärtung, MariaDB, Redis, TLS, `shared/.env`, logrotate und Backup-Cron in einem idempotenten Lauf; Checkliste und Nacharbeiten in `docs/operations/06-neuer-server.md`. Die Units tragen `PartOf=immoware-hub.target` (`deploy/systemd/immoware-hub.target`), damit `systemctl stop immoware-hub.target` alle Hub-Dienste gemeinsam anhält. `HUB_HASH_PEPPER` ist in production Pflicht (`hub:doctor` meldet sonst fail und `deploy.sh` bricht ab).

1. nginx: `deploy/nginx/immoware.muellerhv.de.conf` nach `/etc/nginx/sites-available/`, Symlink nach `sites-enabled`, Zertifikat per certbot, `nginx -t`, `systemctl reload nginx`.
2. php-fpm Pool `/etc/php/8.4/fpm/pool.d/immoware.conf`: `user = immoware`, `listen = /run/php/php8.4-fpm-immoware.sock`, `listen.owner = www-data`, Werte aus `docker/php/www.conf` übernehmen. OPcache-Werte aus `docker/php/opcache.ini`.
3. Worker: entweder `deploy/supervisor/immoware-hub-worker.conf` nach `/etc/supervisor/conf.d/` oder `deploy/systemd/immoware-hub-worker@.service` nach `/etc/systemd/system/` und `systemctl enable --now immoware-hub-worker@1 immoware-hub-worker@2`. Nicht beides.
4. Scheduler: `deploy/systemd/immoware-hub-scheduler.service` installieren, `systemctl enable --now immoware-hub-scheduler.service`. Der Dienst läuft dauerhaft mit `schedule:work` (Type=simple, Restart=always, KillMode=process), wie der Scheduler-Container in `compose.yaml`; Laravel entscheidet anhand `config/hub/sync.php` (Zeitzone Europe/Berlin) und der Modul-Provider, was läuft. Änderungsvermerk 12.09.2026: Der frühere minütliche Timer mit `schedule:run` (Type=oneshot) ist entfallen. Grund: Die Sync-Kommandos laufen mit `runInBackground()`; `schedule:run` endete sofort, systemd beendete mit dem Standard KillMode=control-group die gerade gestarteten Hintergrundprozesse, `schedule:finish` lief nie und der `withoutOverlapping`-Mutex blieb bis zu 24 Stunden gesetzt (kein Sync-Dispatch). Ein noch installierter Timer ist mit `systemctl disable --now immoware-hub-scheduler.timer` zu entfernen.
4a. Reverse Proxy: `TRUSTED_PROXIES` in der `.env` auf die IP oder das CIDR des TLS-Proxys setzen (nie `*`). Ohne diesen Wert sieht die Anwendung die Proxy-Adresse als Client-IP; IP-Allowlist der API-Keys, Login-Throttle und `ip_address_hash` im Audit sind dann wirkungslos. Die Auswertung der Variable liegt in `bootstrap/app.php` (Fix-Lauf 12.09.2026, Modul Core).
5. Erstes Deploy: `sudo -u immoware DEPLOY_REPO=<url> deploy/scripts/deploy.sh main`.

### 4.3 deploy.sh

Ablauf (Abbruch bei jedem Fehler, `set -Eeuo pipefail`): Clone des Refs in ein neues Release-Verzeichnis, `.env` und `storage` aus `shared` einhängen, `composer install --no-dev --optimize-autoloader --classmap-authoritative`, `hub:doctor` als Vorprüfung, Wartungsmodus des laufenden Releases, `migrate --force`, `config:cache`, `route:cache`, `view:cache`, `event:cache`, atomares Umschalten des Symlinks, `php-fpm reload`, `queue:restart`, `up`, Health-Check gegen `/health/database` und `/health/queue` (sechs Versuche im Abstand von 10 Sekunden, Deploy gültig bei HTTP 200 auf beiden; bei 503 werden vor dem Abbruch die Health-Details beider Endpunkte ausgegeben). Der Gesamtstatus `/health` wird nur informativ geholt, weil er den Immoware24-Spiegel enthält und bei stale Daten oder einer neuen Connection ohne Sync-Stand 503 liefert (Änderungsvermerk 12.09.2026), Aufräumen alter Releases (Standard fünf).

Schlägt die Migration fehl, bleibt `current` auf dem alten Release und die Anwendung im Wartungsmodus; dann `php artisan up` im alten Release und Ursache analysieren. Schlägt der Health-Check nach dem Umschalten fehl: `deploy/scripts/deploy.sh --rollback` stellt den Symlink zurück. Migrationen werden nicht zurückgerollt; bei Schemaänderungen gilt das Backup vor dem Deploy (`deploy/scripts/backup.sh` unmittelbar vorher ausführen).

## 5. GitHub Actions

- `.github/workflows/ci.yml`: bei Push auf `main` oder `master` und bei Pull Requests. Jobs: Qualität (`composer validate --strict`, `composer install`, `composer audit`, `pint --test`, PHPStan 2.2.13 nur im CI installiert), Tests mit SQLite in-memory (inklusive `migrate:fresh --env=testing`), Tests gegen MariaDB 11.4 als Service (`migrate:fresh` mit Integritätstriggern, Prüfung der vier Trigger aus `docs/architecture/03-mariadb-triggers.sql`, Rollback-Probe, Testsuite mit `HUB_DB_TRIGGERS=false`, weil Tests in Security und Admin Manipulationen an `audit_logs` simulieren).
- `.github/workflows/deploy.yml`: nur manuell (`workflow_dispatch`) mit Eingabe `DEPLOY` als Bestätigung, Zielumgebung `staging` oder `production` (GitHub Environments mit Reviewern hinterlegen). Führt `deploy/scripts/deploy.sh` per SSH aus. Die Secrets `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KNOWN_HOSTS` sind Platzhalter und im Repository nicht gesetzt; ohne sie bricht der Workflow ab. Kein automatischer Deploy bei Push.

Die zuvor vorhandenen Laravel-Standard-Workflows (dependabot-auto-merge, issues, pull-requests, update-changelog, tests) wurden am 12.09.2026 entfernt, weil sie auf das Laravel-Skeleton-Repository zugeschnitten waren.

## 6. Prüfstand der Konfigurationen

| Datei | Prüfung am 12.09.2026 |
|---|---|
| `compose.yaml`, `ci.yml`, `deploy.yml` | YAML mit PyYAML `safe_load` geladen, Services und Jobs vollständig |
| `deploy/scripts/*.sh`, `docker/entrypoint.sh` | `bash -n` ohne Fehler |
| `docker/nginx/*.conf`, `deploy/nginx/*.conf` | manuell geprüft, `nginx -t` in der Entwicklungsumgebung nicht verfügbar, vor Inbetriebnahme ausführen |
| `Dockerfile` | Build in der Entwicklungsumgebung nicht möglich (kein Docker-Daemon, kein Netz), erster Build auf Staging |
| systemd, supervisord | `systemd-analyze verify` und `supervisorctl reread` auf dem Zielsystem ausführen |

## 7. Offene Punkte

| Punkt | Zuständig | Hinweis |
|---|---|---|
| `bootstrap/app.php` registriert kein `trustProxies`; hinter dem TLS-Proxy erkennt Laravel HTTPS nur, weil nginx im Container `HTTPS` anhand von `X-Forwarded-Proto` setzt | Entwicklung (Core) | Vor Go-live `$middleware->trustProxies(at: ...)` mit dem Proxy-Netz ergänzen |
| Health-Check `queue` zählt die Tabelle `jobs`; bei `QUEUE_CONNECTION=redis` ist die Tiefe dort immer 0 | Entwicklung (Api, Sync) | Monitoring nutzt ergänzend `queue:monitor`, siehe `03-monitoring.md` Abschnitt 5 |
| Erster Docker-Build und `nginx -t` ungetestet | Betrieb | Auf Staging vor Produktion |
| Hoster, Serverdimensionierung, AV-Vertrag | Geschäftsführung | Freigabe erforderlich |
