# 06 Neuer dedizierter Server

Stand: 20.09.2026
Gesellschaft: Hausverwaltung Müller GmbH (Betreiberin des Hubs)
Domains: https://immoware.muellerhv.de (Hub), https://mail.muellerhv.de (Mail- und Vorgangsbearbeitung)
Betriebsvariante: B, Host-Installation ohne Docker (`docs/operations/01-deployment.md` Abschnitt 4), weil ein dedizierter Server zur Verfügung steht.

## 1. Ergebnis

Ein frischer Ubuntu-Server wird mit einem einzigen Skriptlauf produktionsbereit eingerichtet: `deploy/scripts/server-bootstrap.sh`. Das Skript ist idempotent, arbeitet als root, enthält keine Secrets und erzeugt alle Passwörter selbst. Danach bleiben bewusst manuelle Schritte (Admin-Nutzer, 2FA, Immoware-Connection, GPG-Schlüssel für Backups, GitHub-Secrets), weil sie Freigaben oder Schlüsselmaterial außerhalb des Servers betreffen.

Aufwand: etwa 20 bis 30 Minuten Skriptlaufzeit (apt, composer install), plus 30 Minuten Nacharbeiten und Abnahme. Risiko: gering, solange DNS vor dem TLS-Schritt auf den neuen Server zeigt und der alte Server bis zur Abnahme unangetastet bleibt.

## 2. Vorbedingungen

| Nr. | Prüfpunkt | Erledigt |
|---|---|---|
| V1 | Server mit Ubuntu 24.04 LTS (getesteter Zielstand) oder Ubuntu 26.04 LTS (unterstützt, siehe Abschnitt 9). Andere Releases bricht das Skript ab. Standort EU, AV-Vertrag mit dem Hoster liegt vor (Architekturentscheidung Abschnitt 7) | |
| V2 | Root-Zugang per SSH (Passwort oder Schlüssel), öffentliche IPv4 bekannt; IPv6 nur, wenn sie auch im DNS eingetragen wird | |
| V3 | Mindestens 4 GB RAM, 2 vCPU, 40 GB SSD. MariaDB bekommt 512 MB Buffer Pool, Redis 256 MB (per Variablen anpassbar) | |
| V4 | DNS: A-Record (und falls vorhanden AAAA) für `immoware.muellerhv.de` **und** `mail.muellerhv.de` auf die neue IP. TTL vorher auf 300 s senken. Prüfung: `dig +short A immoware.muellerhv.de @1.1.1.1` | |
| V5 | **Keine Änderung an MX, SPF, DKIM, DMARC.** `mail.muellerhv.de` ist nur ein Web-Hostname, kein Mailserver (`docs/mail/09-deployment.md` Abschnitt 1). Vorher prüfen, dass kein bestehender Eintrag `mail.muellerhv.de` (Autodiscover, Webmail) existiert | |
| V6 | GitHub: Repository `muellerhv/IMMOWARE24`, Zielbranch oder Tag festgelegt (`BRANCH`). Bei privatem Repository wird ein Deploy Key (nur Lesen) benötigt, den das Skript beim ersten Lauf ausgibt | |
| V7 | E-Mail-Adresse für Let's Encrypt (`ADMIN_EMAIL`), erreicht den Betrieb | |
| V8 | Öffentlicher SSH-Schlüssel des Administrators (`SSH_PUBKEY`), damit das Skript die Passwort-Anmeldung abschalten kann | |
| V9 | Optional: öffentlicher Schlüssel des GitHub-Actions-Deploy-Nutzers (`DEPLOY_SSH_PUBKEY`), sonst später manuell in `/home/immoware/.ssh/authorized_keys` | |
| V10 | GPG-Schlüsselpaar für Backups vorhanden, privater Schlüssel offline verwahrt (`02-backup-restore.md` Abschnitt 3); nur der öffentliche Teil kommt auf den Server | |
| V11 | Wenn ein alter Server abgelöst wird: aktuelles Backup (`backup.sh`) und Kopie der alten `shared/.env` gesichert (APP_KEY, HUB_HASH_PEPPER und die verschlüsselten Immoware-Zugangsdaten hängen daran, siehe Abschnitt 7) | |

## 3. Ausführung des Skripts

Als root auf dem neuen Server. Das Skript kann aus einem lokalen Checkout oder allein kopiert ausgeführt werden; ohne Checkout klont es das Repository selbst nach `/var/www/immoware-hub/bootstrap-src`.

```
apt-get update && apt-get install -y git
git clone --depth 1 --branch main git@github.com:muellerhv/IMMOWARE24.git /root/IMMOWARE24    # oder Skript einzeln kopieren

ADMIN_EMAIL="it@muellerhv.de" \
SSH_PUBKEY="ssh-ed25519 AAAA... admin@muellerhv" \
BRANCH="main" \
bash /root/IMMOWARE24/deploy/scripts/server-bootstrap.sh --dry-run        # 1. nur anzeigen

ADMIN_EMAIL="it@muellerhv.de" SSH_PUBKEY="..." BRANCH="main" \
bash /root/IMMOWARE24/deploy/scripts/server-bootstrap.sh                  # 2. ausführen
```

Variablen (alle per Umgebung überschreibbar): `HUB_DOMAIN`, `MAIL_DOMAIN`, `DEPLOY_USER` (immoware), `APP_DIR` (/var/www/immoware-hub), `DB_NAME`, `DB_USER`, `ADMIN_EMAIL`, `REPO_URL`, `BRANCH`, `SSH_PUBKEY`, `DEPLOY_SSH_PUBKEY`, `SERVER_IPV4`, `PHP_SOURCE` (auto, distro, ondrej, sury; Standard auto, siehe Abschnitt 9), `MARIADB_SOURCE` (auto, mariadb = 11.4 LTS von mariadb.org, distro = Ubuntu-Paket; Standard auto: 24.04 mariadb, 26.04 distro), `MARIADB_MIN_DISTRO_VERSION` (11.4), `MARIADB_BUFFER_POOL`, `REDIS_MAXMEMORY`, `BOOTSTRAP_FAKE_RELEASE` (nur mit `--dry-run`).

Optionen: `--dry-run` (nichts verändern), `--skip-tls` (kein certbot, nginx bleibt HTTP-only, sinnvoll solange DNS noch auf den alten Server zeigt), `--skip-deploy` (alles einrichten, `deploy.sh` nicht ausführen).

Was das Skript tut (Reihenfolge wie in der Ausgabe):

1. apt update und upgrade, unattended-upgrades (Sicherheitsupdates, kein automatischer Neustart), Zeitzone Europe/Berlin.
2. ufw (22, 80, 443 offen, Rest zu), fail2ban für sshd, sshd-Härtung (`PermitRootLogin prohibit-password`; `PasswordAuthentication no` nur, wenn `SSH_PUBKEY` gültig war und hinterlegt wurde).
3. Nutzer `immoware` (Passwort gesperrt, Anmeldung nur per Schlüssel), eigener ed25519-Schlüssel für `git clone`, sudoers nur für `systemctl reload php8.4-fpm` und `systemctl stop|start|restart|status immoware-hub-*` (das braucht `deploy.sh`).
4. Verzeichnisse nach 01-deployment.md Abschnitt 4.1 plus `/etc/immoware-hub`, `/var/log/immoware-hub`, `/var/backups/immoware-hub`, `/var/www/letsencrypt`.
5. Erreichbarkeit des Repositories als `immoware` prüfen. Ist es nicht erreichbar, gibt das Skript den öffentlichen Deploy-Schlüssel aus und bricht ab; nach Hinterlegen in GitHub (Settings, Deploy keys, nur Lesen) erneut starten.
6. PHP 8.4 (fpm, cli, mysql, redis, intl, zip, mbstring, xml, curl, bcmath, gd, opcache). Quelle nach `PHP_SOURCE=auto`: zuerst das Distro-Paket `php8.4-fpm`, sonst ppa:ondrej/php, wenn Launchpad das Release veröffentlicht (Prüfung per `apt-cache policy` nach `add-apt-repository`, bei Fehlen wird das PPA wieder entfernt), sonst Abbruch mit Meldung. Kein automatischer Wechsel auf PHP 8.5. Composer 2 mit SHA-384-Prüfung gegen `composer.github.io/installer.sig`, php.ini-Werte aus `docker/php/php.ini`, OPcache aus `docker/php/opcache.ini`, php-fpm Pool `immoware` mit Unix-Socket `/run/php/php8.4-fpm-immoware.sock` (Werte aus `docker/php/www.conf`). Der Standard-Pool `www` wird deaktiviert.
7. MariaDB 11.x LTS (24.04: 11.4 von deb.mariadb.org, Signaturschlüssel per festem Fingerabdruck geprüft; 26.04: Distro-Paket, sofern mindestens 11.4, erwartet 11.8; die installierte Version wird protokolliert), `bind-address 127.0.0.1`, utf8mb4, Binlog ROW mit 7 Tagen Aufbewahrung, `log_bin_trust_function_creators` für die Integritätstrigger, nicht-interaktive Absicherung (anonyme Nutzer, Testdatenbank, Root nur lokal über unix_socket), Datenbank und Nutzer mit Zufallspasswort.
8. Redis über das Paket `redis-server` (nur 127.0.0.1, `requirepass` zufällig, `appendonly yes`, `maxmemory-policy noeviction`). Fehlt das Paket oder fehlt `/etc/redis/redis.conf` nach der Installation, bricht das Skript mit Hinweis auf Valkey ab; es wechselt nicht stillschweigend.
9. `shared/.env` aus `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `MAIL_APP_DOMAIN`, DB- und Redis-Werte, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES` leer (kein Proxy vor nginx), `LOG_CHANNEL=daily`, alle Schreib- und Versandflags false. `APP_KEY` und `HUB_HASH_PEPPER` werden einmalig erzeugt (Format wie `key:generate`); eine vorhandene `.env` wird nie überschrieben, `php artisan key:generate` darf danach nicht mehr laufen.
10. nginx: zunächst HTTP-only-Blöcke für beide Domains (ACME-Challenge, Health-Check), Ubuntu-Default-Site entfernt.
11. TLS: `dig`-Prüfung, dass A (und ggf. AAAA) beider Domains auf die Server-IP zeigen, sonst Abbruch mit klarer Meldung. Dann `certbot certonly --webroot` je Domain, Verlängerung über `certbot.timer` mit Deploy-Hook `systemctl reload nginx`. Anschließend werden die Server-Blöcke aus `deploy/nginx/*.conf` unverändert installiert. Bewusste Abweichung: nicht `certbot --nginx`, weil das Plugin die Konfigurationsdateien umschreiben würde; die Dateien im Repository bleiben die Referenz.
12. systemd-Units aus `deploy/systemd` (Hub-Worker Instanzen 1 und 2, Mail-Worker `high` und `sync`, Scheduler, Target `immoware-hub.target`) mit Umgebungsdateien `/etc/immoware-hub/worker.env`, `mail-worker-high.env` (`mail-high`, Timeout 300), `mail-worker-sync.env` (`mail-sync,mail-ai`, Timeout 600). Units werden aktiviert, aber erst nach dem ersten Deploy gestartet.
13. logrotate für `shared/storage/logs/*.log` und `/var/log/immoware-hub/*.log` (14 Tage), Backup-Cron `/etc/cron.d/immoware-hub-backup` täglich 02:00 mit Umgebung `/etc/immoware-hub/backup.env`. **`BACKUP_GPG_RECIPIENT` ist dort leer und muss gesetzt werden, sonst bricht `backup.sh` ab** (siehe Abschnitt 4).
14. Erster Deploy: `deploy/scripts/deploy.sh <BRANCH>` als `immoware` (Clone, `composer install`, `hub:doctor`, `migrate --force`, Caches, Symlink `current`, php-fpm reload, Health-Check gegen `/health/database` und `/health/queue`). Danach Start aller Units und Ausgabe von `hub:doctor`.

Am Ende: Zusammenfassung mit allen Erkennungen (Release, PHP-Quelle, MariaDB-Quelle und Version, Redis-Paket, certbot), Pfad der Credentials-Datei `/root/immoware-hub-credentials.txt` (0600, enthält DB- und Redis-Passwort; dieselben Werte stehen in `shared/.env`), nächste Schritte.

Erneuter Lauf: jederzeit möglich. Vorhandene Secrets, `.env`, Zertifikate und `backup.env` bleiben unverändert; Konfigurationsdateien werden nur bei Änderung neu geschrieben; `deploy.sh` läuft als Update.

## 4. Nacharbeiten

| Nr. | Schritt | Befehl oder Ort | Erledigt |
|---|---|---|---|
| N1 | Admin-Nutzer anlegen (Rolle owner) | `sudo -u immoware -H bash -c 'cd /var/www/immoware-hub/current && php artisan hub:user:create <email> --role=owner --name="<Name>"'` | |
| N2 | Erste Anmeldung unter https://immoware.muellerhv.de, 2FA einrichten (Pflicht), Wiederherstellungscodes sicher ablegen | Browser | |
| N3 | Immoware-Connection anlegen: technischer Nutzer, Freigabe-Link, Freigabe-Passwort über die Admin-UI (verschlüsselt in der Datenbank, nie in der `.env`); Probe ausführen | Admin-UI, `docs/immoware/04-authentication.md` | |
| N4 | Backup: öffentlichen GPG-Schlüssel importieren, Empfänger eintragen, Probelauf | `sudo -u immoware -H gpg --import backup-public.asc`; `BACKUP_GPG_RECIPIENT=<Key-ID>` in `/etc/immoware-hub/backup.env`; `sudo -u immoware bash -c '. /etc/immoware-hub/backup.env && /var/www/immoware-hub/current/deploy/scripts/backup.sh'` | |
| N5 | Offsite-Ziel für Backups (`BACKUP_OFFSITE_CMD`, S3-kompatibel EU, Object Lock für `audit-anchors`) | `/etc/immoware-hub/backup.env`, `02-backup-restore.md` Abschnitt 3 | |
| N6 | Restore-Test mit dem ersten Backup in eine Prüfdatenbank (privater GPG-Schlüssel nur für den Test einhängen, danach entfernen) | `deploy/scripts/restore-test.sh <datei>`, Protokoll in `02-backup-restore.md` Abschnitt 6 | |
| N7 | GitHub Actions für spätere Deploys: Schlüsselpaar erzeugen, öffentlichen Teil nach `/home/immoware/.ssh/authorized_keys`, Secrets in den Environments `staging` und `production` setzen: `DEPLOY_SSH_KEY` (privater Teil), `DEPLOY_HOST` (immoware.muellerhv.de oder IP), `DEPLOY_USER` (immoware), `DEPLOY_KNOWN_HOSTS` (Ausgabe von `ssh-keyscan -t ed25519 <host>`) | GitHub, Repository Settings, Environments; `.github/workflows/deploy.yml` | |
| N8 | Monitoring anbinden (Health-Endpunkte, Worker-Heartbeat, Alarme A1 bis A6) | `03-monitoring.md` | |
| N9 | Mail-Modul: erst nach Abnahme des Hubs. Google-Cloud-Projekt, OAuth-Client, Pub/Sub, dann `MAIL_IMPORT_ENABLED=true` einzeln nach Freigabe | `docs/mail/09-deployment.md` Abschnitt 6 | |
| N10 | Wenn ein alter Server abgelöst wurde: dort Worker und Scheduler stoppen, Cron deaktivieren, Server erst nach erfolgreicher Abnahme und Datenübernahme abschalten | alter Server | |

## 5. Abnahmeprüfung

| Nr. | Prüfung | Erwartung | Erledigt |
|---|---|---|---|
| A1 | `curl -fsS https://immoware.muellerhv.de/health/database` und `/health/queue` | HTTP 200, `"status":"ok"` | |
| A2 | `curl -sS https://immoware.muellerhv.de/health` | 200, oder 503 nur wegen fehlendem Sync-Stand der neuen Connection (`immoware` stale), kein Datenbank- oder Queue-Fehler | |
| A3 | `curl -fsS https://mail.muellerhv.de/up` | 200 | |
| A4 | `curl -sS -o /dev/null -w '%{http_code}' https://mail.muellerhv.de/api/v1` | 404 (Pfadtrennung des Mail-Hosts) | |
| A5 | `sudo -u immoware -H bash -c 'cd /var/www/immoware-hub/current && php artisan hub:doctor'` | keine Zeile `fail`; `warn` nur für nicht eingerichtete Integrationen | |
| A6 | `systemctl status 'immoware-hub-*'` | alle fünf Units `active (running)` | |
| A7 | `php artisan schedule:list` und nach fünf Minuten `php artisan hub:heartbeat:check worker --max-age=180` sowie `... scheduler ...` | Exit 0 | |
| A8 | Login mit 2FA unter beiden Hostnamen; unter mail.muellerhv.de liefern `/admin/connections` und `/admin/users` 404 | wie erwartet | |
| A9 | TLS: `openssl s_client -connect immoware.muellerhv.de:443 -servername immoware.muellerhv.de </dev/null 2>/dev/null \| openssl x509 -noout -dates -issuer`, `certbot renew --dry-run` | gültiges Let's-Encrypt-Zertifikat, Dry-Run erfolgreich | |
| A10 | Firewall: `ufw status verbose`; von außen `nmap -p 22,80,443,3306,6379 <ip>` | nur 22, 80, 443 offen | |
| A11 | Erster Immoware-Sync (`hub:sync:dispatch documents --mode=full` oder über die Admin-UI), Datenalter im Dashboard sinkt | kein Fehler in `storage/logs`, keine DLQ-Einträge | |
| A12 | Backup-Lauf N4 erfolgreich, Datei in `/var/backups/immoware-hub/daily`, Restore-Test N6 protokolliert | | |
| A13 | Manueller Deploy über GitHub Actions (`workflow_dispatch`, Bestätigung `DEPLOY`) gegen `staging` oder mit Freigabe gegen `production` | Workflow grün, Health-Check grün | |

## 6. Rollback

- Fehlgeschlagener Skriptlauf: Das Skript bricht beim ersten Fehler ab (`set -euo pipefail`) und hinterlässt einen konsistenten Zwischenstand. Ursache beheben und erneut ausführen; alle Schritte sind idempotent.
- Fehlgeschlagener erster Deploy: `deploy.sh` schaltet `current` erst nach erfolgreicher Migration um. Ursache in der Ausgabe prüfen (`hub:doctor`, `composer`, Migration), dann erneut `sudo -u immoware -H env DEPLOY_REPO=<url> bash -s -- main < deploy/scripts/deploy.sh`.
- Fehlgeschlagenes späteres Release: `deploy/scripts/deploy.sh --rollback` als `immoware` stellt den Symlink zurück (Migrationen werden nicht zurückgerollt, dafür gilt das Backup vor dem Deploy).
- Serverwechsel gescheitert: DNS auf den alten Server zurückstellen (TTL 300 s), dort Worker und Scheduler wieder starten. Der alte Server wird deshalb erst nach der Abnahme abgeschaltet (N10).
- Vollrestore der Datenbank nur mit Freigabe der Geschäftsführung, Vier-Augen-Prinzip und Protokoll nach `02-backup-restore.md` Abschnitt 5.

## 7. Was ausdrücklich nicht automatisiert wird

| Punkt | Grund |
|---|---|
| Anlegen von Nutzern, 2FA | Zugangsdaten werden persönlich übergeben, 2FA ist an ein Gerät gebunden |
| Immoware-Connection (Freigabe-Link, technischer Nutzer, Freigabe-Passwort) | Zugangsdaten des Mandanten liegen nicht auf dem Server außerhalb der verschlüsselten Datenbank; Eingabe nur über die Admin-UI mit Audit |
| Privater GPG-Schlüssel für Backups | liegt nie auf dem Server (`02-backup-restore.md`) |
| GitHub-Secrets und Deploy Key | Schlüsselmaterial gehört in den Secret-Store von GitHub, nicht in ein Skript |
| Übernahme einer alten `.env` oder Datenbank | `APP_KEY` und `HUB_HASH_PEPPER` verschlüsseln beziehungsweise hashen Bestandsdaten. Bei einem Serverwechsel mit Datenübernahme müssen beide Werte aus der alten `.env` übernommen werden, sonst sind verschlüsselte Zugangsdaten und die Hash-Spalten unbrauchbar. Das Skript erzeugt neue Werte nur, wenn keine `.env` existiert; die alte `.env` also vor dem Lauf nach `shared/.env` kopieren (0600, Eigentümer immoware) |
| Aktivieren von Schreib- oder Versandflags (`IMMOWARE_WRITE_*`, `MAIL_*_ENABLED`) | nur nach Vier-Augen-Prüfung und protokollierter Freigabe der Geschäftsführung (`docs/immoware/05-write-capabilities.md`) |
| DNS-Änderungen, MX/SPF/DKIM/DMARC | beim Registrar oder DNS-Anbieter; MX und Mailauthentifizierung bleiben unangetastet |
| Offsite-Backup-Ziel und Object Lock | Anbieterwahl, AV-Vertrag, Zugangsdaten außerhalb des Servers |
| Reboot nach Kernel-Updates | unattended-upgrades startet nicht automatisch neu; Neustart im Wartungsfenster nach `04-runbook.md` |
| `certbot --nginx` und Änderungen an den nginx-Dateien | die Dateien in `deploy/nginx` sind die Referenz und werden vom Skript unverändert installiert |

## 8. Prüfstand

| Datei | Prüfung am 20.09.2026 |
|---|---|
| `deploy/scripts/server-bootstrap.sh` | `bash -n` ohne Fehler, `--dry-run` in der Entwicklungsumgebung (Ubuntu 24.04, root) vollständig durchlaufen, ebenso `BOOTSTRAP_FAKE_RELEASE=26.04 ... --dry-run` (simuliertes 26.04, Exit 0) und `BOOTSTRAP_FAKE_RELEASE=22.04` (Abbruch wie vorgesehen). HTTP-only-nginx-Block gerendert und geprüft. shellcheck in der Entwicklungsumgebung nicht verfügbar. Kein echter Serverlauf: vor Produktion auf einer Wegwerf-VM mit dem Zielrelease ausführen |
| `deploy/scripts/deploy.sh`, `backup.sh`, `restore-test.sh` | `bash -n` ohne Fehler; Pfade, Nutzer und Socket stimmen mit dem Bootstrap überein (`/var/www/immoware-hub`, `immoware`, `/run/php/php8.4-fpm-immoware.sock`, `/usr/bin/php`, `/usr/local/bin/composer`) |
| `deploy/systemd/*` | Hub-Worker `@1`, `@2`, Mail-Worker `@high`, `@sync`, Scheduler; neu `immoware-hub.target`, weil die Units `PartOf=immoware-hub.target` tragen. `systemd-analyze verify` läuft im Skript auf dem Zielsystem |
| `.env.example` | `HUB_HASH_PEPPER` ergänzt: in production Pflicht (`hub:doctor` fail, sonst bricht `deploy.sh` ab), fehlte bislang |

## 9. Ubuntu 26.04 LTS

Stand der Recherche: 20.09.2026, ausschließlich über Web-Suchergebnisse (Snippets), weil packages.ubuntu.com, launchpad.net, packages.sury.org und deb.mariadb.org aus der Entwicklungsumgebung nicht direkt abrufbar waren. Alle Angaben vor dem ersten echten Lauf auf dem Zielserver mit `apt-cache policy` gegenprüfen.

### 9.1 Rechercheergebnis

| Punkt | Ergebnis | Quelle und Sicherheit |
|---|---|---|
| Codename | `resolute` (Resolute Raccoon), Release April 2026 | Ubuntu-Release-Notes, Launchpad-Seiten `ubuntu/resolute`; sicher |
| PHP in der Distro | PHP 8.5 (Pakete `php8.5-*`), kein `php8.4-*` in main oder universe | packages.ubuntu.com/resolute, Launchpad; sicher. Das Projekt ist auf PHP 8.4 getestet (composer.json, CI), 8.5 wird nicht ohne Freigabe eingesetzt |
| ppa:ondrej/php | veröffentlicht nur für `noble` und `jammy`, für `resolute` liefert Launchpad 404. Der Maintainer verweist für 26.04 auf packages.sury.org/php als kanonische Quelle (PHP 5.6 bis 8.6) | Launchpad-PPA-Seite (direkt abgerufen), Snippets; sicher zum Recherchestand, kann sich ändern |
| MariaDB in der Distro | 11.8 LTS (`mariadb-server 1:11.8.6-5`) in main | Launchpad, ubuntuupdates.org; weitgehend sicher |
| deb.mariadb.org | Suiten `noble` und `jammy`, keine Suite `resolute` für 11.4 | Snippets (linuxcapable, linuxconfig); mittlere Sicherheit, direkt nicht prüfbar |
| Redis | Ubuntu 26.04 führt Valkey 9.0 als Standard (`valkey-server` in main). Ein Paket `redis-server` ist gelistet, laut Snippets als Übergangspaket beziehungsweise mit Alternativen zu `valkey-tools`; ob es einen echten Redis 7 oder ein Valkey-Binary mit `/etc/valkey` liefert, ist nicht verifiziert | packages.ubuntu.com/resolute/redis-server, ubuntu.com Blog; unsicher im Detail |
| certbot | `certbot 4.0.0`, `python3-certbot-nginx 4.0.0-3` in universe | Snippets; weitgehend sicher |

### 9.2 Was das Skript auf 26.04 erkennt und entscheidet

- Release über `lsb_release` beziehungsweise `/etc/os-release`. Unterstützt sind 24.04 und 26.04, alles andere bricht in Schritt 0 ab. `BOOTSTRAP_FAKE_RELEASE=26.04` simuliert das Release nur zusammen mit `--dry-run`; Paketkandidaten des echten Systems werden dabei bewusst nicht übernommen.
- PHP (`PHP_SOURCE=auto`): Distro-Paket `php8.4-fpm`, sonst ppa:ondrej/php mit Prüfung per `apt-cache policy` (bei Fehlen wird das PPA wieder entfernt), sonst Abbruch. Nach dem Recherchestand endet 26.04 damit im Abbruch, weil weder die Distro noch das PPA PHP 8.4 liefern. Der Weg über packages.sury.org ist als `PHP_SOURCE=sury` vorbereitet, wird nie automatisch gewählt und gilt als ungetestet: Der Signaturschlüssel wird aus dem Keyring-Paket `debsuryorg-archive-keyring.deb` installiert und nicht gegen einen fest hinterlegten Fingerabdruck geprüft, weil dieser aus der Entwicklungsumgebung nicht verifizierbar war.
- MariaDB (`MARIADB_SOURCE=auto`): auf 26.04 Distro-Paket, wenn die Kandidatenversion mindestens `MARIADB_MIN_DISTRO_VERSION` (11.4) erreicht, erwartet 11.8. Andernfalls deb.mariadb.org, das bei fehlender Suite mit Meldung abbricht und den Eintrag wieder entfernt. Die installierte Version steht in der Zusammenfassung.
- Redis: Paket `redis-server` ist Pflicht. Fehlt es, oder fehlt nach der Installation `/etc/redis/redis.conf`, bricht das Skript mit Hinweis auf Valkey ab. Liefert `redis-server` ein Valkey-Binary, läuft das Skript mit Hinweis weiter.
- certbot: Kandidat wird geprüft und protokolliert, Vorgehen unverändert (`certonly --webroot`).

### 9.3 Was ungetestet ist

- Es gab keinen echten Lauf auf Ubuntu 26.04, nur `--dry-run` mit simuliertem Release. Weder PHP 8.4 aus packages.sury.org noch MariaDB 11.8 aus der Distro noch das Redis- oder Valkey-Paket von 26.04 sind mit dem Hub getestet (Tests und CI laufen gegen PHP 8.4 und MariaDB 11.4).
- MariaDB 11.8 statt 11.4: Migrationen und Trigger (`docs/architecture/03-mariadb-triggers.sql`) sind auf 11.4 abgenommen. Vor Produktion `php artisan migrate --force` und `hub:doctor` auf einer Wegwerf-VM mit 26.04 prüfen.
- Valkey statt Redis: Laravel-Queue, Cache und Locks sind gegen Redis 7 getestet. Ein Wechsel ist eine Freigabeentscheidung, keine Skriptautomatik.

### 9.4 Vorgehen bei Fehlschlag auf 26.04

1. Meldung des Skripts lesen, die Zusammenfassung nennt alle Erkennungen. Auf dem Server gegenprüfen: `apt-cache policy php8.4-fpm mariadb-server redis-server certbot`.
2. PHP: Bricht das Skript wegen fehlendem PHP 8.4 ab, Entscheidung der Geschäftsführung beziehungsweise IT-Leitung einholen: entweder `PHP_SOURCE=sury` (Drittquelle, ungetestet, Signaturschlüssel vor dem Lauf manuell prüfen) oder Server mit Ubuntu 24.04 LTS bereitstellen (getesteter Zielstand, geringstes Risiko). PHP 8.5 nur nach Testlauf der gesamten Suite (`php artisan test`, PHPStan) auf 8.5.
3. MariaDB: Bei Abbruch von deb.mariadb.org `MARIADB_SOURCE=distro` setzen, sofern die Distro-Version mindestens 11.4 ist. Liegt sie darunter, kein Betrieb ohne Rücksprache.
4. Redis: Bei Abbruch wegen fehlendem `redis-server` oder Valkey-Übergangspaket nicht manuell auf `valkey` ausweichen. Erst Freigabe, dann Skript anpassen (Paket, Konfigurationspfad `/etc/valkey`, Unit `valkey-server`, `redis-cli` über `valkey-redis-compat`) und auf einer Wegwerf-VM testen.
5. Alle Schritte sind idempotent: nach Behebung Skript erneut starten. Empfehlung: Ubuntu 24.04 LTS bleibt bis zu einem abgenommenen Referenzlauf auf 26.04 der Zielstand für Produktion.

