# 09 Deployment mail.muellerhv.de

Stand: 12.09.2026. Ergänzt `docs/operations/01-deployment.md`. Die Mail-Domain läuft auf demselben Host, derselben Anwendung und derselben Datenbank wie `immoware.muellerhv.de` (01). Nichts in diesem Dokument wurde gegen einen echten Host geprüft.

## 1. DNS

| Eintrag | Typ | Wert | Bemerkung |
|---|---|---|---|
| mail.muellerhv.de | A | IPv4 des Hub-Hosts | wie immoware.muellerhv.de |
| mail.muellerhv.de | AAAA | IPv6 des Hub-Hosts, falls vorhanden | |

**Keine Änderung an MX, SPF, DKIM, DMARC.** Der Hub versendet keine E-Mails über eigene Server; der Versand erfolgt ausschließlich über die Gmail-API im Namen des Google-Workspace-Postfachs. Die bestehende Mailzustellung für muellerhv.de bleibt unberührt. Der Hostname "mail" ist nur ein Web-Hostname, kein Mailserver; ein MX-Eintrag auf mail.muellerhv.de darf nicht gesetzt werden. Vor DNS-Änderung prüfen, dass kein bestehender Eintrag `mail.muellerhv.de` (zum Beispiel Autodiscover, Webmail) existiert.

## 2. TLS

- Zertifikat für `mail.muellerhv.de` per certbot (Let's Encrypt), eigenes Zertifikat oder SAN-Erweiterung des bestehenden Zertifikats; HTTP-01 über `/.well-known/acme-challenge/`.
- TLS 1.2 und 1.3, HSTS wie beim Hub. Wegen `includeSubDomains` im HSTS-Header von immoware.muellerhv.de gilt HSTS ohnehin nicht domainübergreifend für Geschwister; mail.muellerhv.de setzt seinen eigenen Header.
- Pub/Sub-Push verlangt ein öffentlich gültiges Zertifikat (Snippet, allgemein bekannt), Self-signed ist ausgeschlossen.

## 3. nginx-Server-Block (Host-Betrieb)

Datei `deploy/nginx/mail.muellerhv.de.conf` (Entwurf, Phase 1 anlegen), Aufbau analog `deploy/nginx/immoware.muellerhv.de.conf`:

```nginx
upstream immoware_fpm { server unix:/run/php/php8.4-fpm-immoware.sock; }

server {
    listen 80; listen [::]:80;
    server_name mail.muellerhv.de;
    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl; listen [::]:443 ssl; http2 on;
    server_name mail.muellerhv.de;

    ssl_certificate     /etc/letsencrypt/live/mail.muellerhv.de/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mail.muellerhv.de/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d; ssl_session_cache shared:mail_ssl:10m; ssl_session_tickets off;
    ssl_stapling on; ssl_stapling_verify on;

    root /var/www/immoware-hub/current/public;
    index index.php;
    server_tokens off;
    client_max_body_size 40m;   # Anhänge bis 35 MB plus Reserve

    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
    add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'" always;

    access_log /var/log/nginx/mail.muellerhv.de.access.log;
    error_log  /var/log/nginx/mail.muellerhv.de.error.log warn;

    # Keine Pfad-Allowlist: die Mail-Oberfläche liegt an der Wurzel des Hosts (/, /cases, /approvals, /admin als
    # Mail-Verwaltung). /api wird in nginx abgewiesen; die vollständige Pfadtrennung erzwingt die Anwendung (unten).
    location ~* ^/api(/|$) { return 404; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; return 200 "User-agent: *\nDisallow: /\n"; }
    location / { try_files $uri $uri/ /index.php?$query_string; }

    # Push-Endpunkt (POST /mail/gmail/push[/{token}]): kein Access-Log, nur POST
    location ^~ /mail/gmail/push {
        access_log off;
        limit_except POST { deny all; }
        try_files /index.php?$query_string /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_param HTTPS on;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120s;
        fastcgi_pass immoware_fpm;
    }

    location ~ /\.(?!well-known) { deny all; }
    location ~* ^/(storage|vendor)/ { deny all; }
}
```

Pfadtrennung (Stand 12.09.2026, korrigiert): Die im ursprünglichen Konzept vorgesehene Pfad-Allowlist in nginx (`mail|login|...`, `location / { return 404; }`) ist nicht umsetzbar, weil die Mail-Routen ohne Präfix an der Wurzel des Hosts liegen. Stattdessen beendet die Anwendung auf dem Mail-Host jede Route ohne Domainbindung mit 404 (`MailServiceProvider::registerHostGuard`, Listener auf `Route::matched`), ausgenommen Anmeldung, 2FA, Sicherheitsseiten und Health (`hub.mail.host_allowed_route_prefixes`). Hub-Admin-UI und `/api/v1` sind unter mail.muellerhv.de nicht erreichbar (Test `tests/Feature/Mail/MailHostGuardTest`). nginx weist `/api` zusätzlich ab. Umgekehrt prüft die Middleware `mail.domain`, dass Mail-Routen nur auf dem Mail-Host antworten. Die Anwendung setzt außerdem selbst eine Content-Security-Policy auf allen Mail-Routen (`mail.headers`), unabhängig von nginx. Container-Variante `docker/nginx/mail.muellerhv.de.conf` ohne TLS (Terminierung im vorgelagerten Proxy), eingebunden in `compose.yaml` als zweite Datei unter `/etc/nginx/conf.d/mail.conf`.

## 4. Anwendung

| Einstellung | Wert |
|---|---|
| `APP_URL` | bleibt `https://immoware.muellerhv.de`; Mail-URLs werden über `MAIL_URL=https://mail.muellerhv.de` erzeugt (`URL::forceRootUrl` nur innerhalb der Mail-Routen, alternativ `route()` mit Domain-Parameter) |
| `MAIL_DOMAIN` | `mail.muellerhv.de` (Produktion), `mail-staging.muellerhv.de` (Staging), `mail.test` (Tests) |
| `SESSION_DOMAIN` | leer (hostgebunden) |
| `TRUSTED_PROXIES` | wie Hub, nie `*` |
| `MAIL_*_ENABLED` | alle false; Aktivierung einzeln nach Freigabe |
| `MAIL_GMAIL_CLIENT_ID`, `MAIL_GMAIL_CLIENT_SECRET` | aus Secret-Store; Secret nur serverseitig |
| `MAIL_PUSH_PATH_TOKEN`, `MAIL_PUSH_AUDIENCE`, `MAIL_PUSH_SERVICE_ACCOUNT_EMAIL` | Pub/Sub-Prüfung |
| `MAIL_LEXWARE_API_KEY`, `MAIL_OPENAI_API_KEY`, `MAIL_OPENAI_BASE_URL`, `MAIL_OPENAI_MODEL` | leer = "Nicht eingerichtet" |

Worker (umgesetzt 12.09.2026): zwei zusätzliche Prozesse neben dem bestehenden Hub-Worker, dessen Queue-Liste unverändert bleibt.

| Prozess | Queues | Docker (`compose.yaml`) | supervisord | systemd |
|---|---|---|---|---|
| Mail-Worker high | `mail-high` (Notfalleskalation, SLA-Prüfung, freigegebene Aktionen), `--timeout=300` | Service `mail-worker-high` | `deploy/supervisor/immoware-hub-mail-worker.conf`, Programm `immoware-hub-mail-worker-high` | `deploy/systemd/immoware-hub-mail-worker@.service`, Instanz `high` (`/etc/immoware-hub/mail-worker-high.env`) |
| Mail-Worker sync | `mail-sync,mail-ai` (Import, Abgleich, KI-Vorschläge), `--timeout=600` | Service `mail-worker` | Programm `immoware-hub-mail-worker` | Instanz `sync` (`/etc/immoware-hub/mail-worker-sync.env`) |

Healthcheck der Mail-Worker in `compose.yaml`: Prozessprüfung (`pgrep -f 'queue:work redis --queue=...'`) je Container. Der gemeinsame Worker-Heartbeat (`hub:heartbeat:check worker`) taugt dafür nicht, weil `WorkerHeartbeatJob` auf der Queue `high` des Hub-Workers läuft: ein hängender Mail-Worker bliebe grün, ein Ausfall des Hub-Workers würde gesunde Mail-Worker neu starten. Die Queue-Namen in `compose.yaml` kommen aus denselben Variablen wie die Anwendung (`MAIL_QUEUE_HIGH`, `MAIL_QUEUE_SYNC`, `MAIL_QUEUE_AI`); supervisor und systemd tragen sie fest, `hub:doctor` meldet abweichende Namen als fail (`mail.queues`). Der Redis-`retry_after` (3600) bleibt größer als jeder Mail-Job-Timeout. `hub:doctor` listet Mail-Flags, Provider-Status je Integration, Queues und Bereitschaft (Prüfpunkte `mail.*`).

nginx: eigener Server-Block je Betriebsart, `deploy/nginx/mail.muellerhv.de.conf` (Host, TLS, gleicher php-fpm-Pool) und `docker/nginx/mail.muellerhv.de.conf` (Container `web`, in `compose.yaml` als `/etc/nginx/conf.d/mail.conf` eingebunden). Beide setzen eine Content-Security-Policy für die Mail-Oberfläche, begrenzen den Push-Pfad `/mail/gmail/push` auf POST ohne Zugriffslog und liefern `robots.txt` mit `Disallow: /`.

Zeitpläne (zentral in `routes/console.php`, alle `withoutOverlapping()->onOneServer()`, Zeitzone Europe/Berlin wo angegeben): `mail-gmail-watch-renew` täglich 03:15, `mail-gmail-reconcile` alle 30 Minuten (konfigurierbar), `mail-gmail-send-reconcile` minütlich, `mail-gmail-push-prune` täglich 04:05, `mail:sla:check` minütlich auf `mail-high`, `mail-actions-scheduled` täglich 06:15. Prüfung mit `php artisan schedule:list`. Ein Retention-Lauf (`mail:retention:apply`) ist nicht eingeplant, weil der Befehl noch fehlt (offen).

Umgebungsvariablen: alle `MAIL_*`-Schlüssel stehen als Platzhalter in `.env.example` (Flags false, Zugangsdaten leer). Die Namen in der Tabelle oben (`MAIL_DOMAIN`, `MAIL_PUSH_*`, `MAIL_OPENAI_*`) sind Konzeptnamen; im Code heißen sie `MAIL_APP_DOMAIN`, `MAIL_GMAIL_PUSH_{TOPIC,AUDIENCE,SERVICE_ACCOUNT,PATH_TOKEN}`, `MAIL_AI_{API_KEY,BASE_URL,MODEL}`. Der Pub/Sub-Endpunkt liegt unter `https://mail.muellerhv.de/mail/gmail/push[/{PATH_TOKEN}]` (ohne `/api`, weil alle `/api`-Routen die API-Key-Middleware tragen); Subscription und `MAIL_GMAIL_PUSH_AUDIENCE` müssen darauf zeigen.

## 5. Staging und Produktion

| Aspekt | Staging | Produktion |
|---|---|---|
| Domain | mail-staging.muellerhv.de | mail.muellerhv.de |
| Google-Cloud-Projekt | eigenes Projekt, Testpostfach | Produktionsprojekt, Teampostfächer |
| Lexware | kein Key oder Sandbox | Produktionskey |
| OpenAI | kein Key | erst nach AVV, EU-Projekt |
| Schreib- und Versand-Flags | durch `MailBootGuard` gesperrt | nach Freigabe einzeln |
| Datenbank | eigene Instanz, keine Produktionskopie mit Mailinhalten | Produktion |
| Banner | "Staging: Versand gesperrt" | keins |

Kein Produktions-Refresh-Token darf je in Staging landen. `hub:doctor` prüft dafür (Prüfpunkte `mail.*`): Flags mit derselben Logik wie `MailBootGuard::violations` (staging oder production mit abweichender Domain, Verstoß ist fail), `mail.staging_mailboxes` (Postfächer mit Adresse der Produktionsdomänen außerhalb der Produktionsdomain, fail), `mail.staging_redirect_uri` (Produktions-Redirect-URI aktiv), `mail.watch` (Postfächer mit aktivem Import ohne Watch oder Ablauf unter 24 Stunden, warn) und `mail.queues` (Queue-Namen außerhalb von mail-high, mail-sync, mail-ai, fail).

## 6. Reihenfolge der Inbetriebnahme

1. DNS A/AAAA setzen, Zertifikat ausstellen, nginx-Block aktivieren, `/up` unter mail.muellerhv.de prüfen.
2. Deploy mit Migrationen (`php artisan migrate --force`), alle Flags false, `hub:doctor` grün. Worker-Reihenfolge in `deploy/scripts/deploy.sh`: vor der Migration `queue:restart`, dann Stop von `immoware-hub-mail-worker-high:*`, `immoware-hub-mail-worker:*`, `immoware-hub-worker:*` (supervisor) bzw. `immoware-hub-mail-worker@*`, `immoware-hub-worker@*` (systemd), Warten auf das Ende aller `queue:work`-Prozesse, Migration, Umschalten, Start in umgekehrter Reihenfolge (Hub-Worker, Mail-Worker sync, Mail-Worker high). Die Mail-Worker haben autorestart bzw. Restart=always und dürfen deshalb nicht nur `queue:restart` erhalten.
3. Anmeldung unter mail.muellerhv.de mit vorhandenem Nutzer und 2FA prüfen; `/admin` unter mail.muellerhv.de zeigt die Mail-Verwaltung (mail.admin.*), die Hub-Admin-Seiten (z. B. `/admin/connections`, `/admin/users`) und `/api/v1` liefern dort 404.
4. Google-Cloud-Projekt, Consent Screen intern, Pub/Sub-Topic und Subscription einrichten; OAuth-Verbindung eines Testpostfachs herstellen.
5. `MAIL_IMPORT_ENABLED=true` (nur Lesen), Watch-Status beobachten, History-Abgleich prüfen.
6. Nach Abnahmefällen 1 bis 20 und Freigabe der Geschäftsführung: `MAIL_GMAIL_DRAFTS_ENABLED`, dann `MAIL_GMAIL_SEND_ENABLED` mit einem Testempfänger.
7. Lexware und Immoware-Schreibpfad jeweils einzeln nach eigener Freigabe.

## 7. Monitoring (Ergänzung zu 03-monitoring.md)

Alarme: Watch abgelaufen oder nicht bestätigt, History-404-Häufung, Push-Auth-Fehler, Token-Refresh-Fehler, DLQ-Einträge auf `mail-*`, P0-Vorgänge ohne Bestätigung, Versandabgleich `not_found`, Lexware 401/429, OpenAI-Fehlerquote. Health-Endpunkt `/up` bleibt; Mail-spezifische Kennzahlen im Dashboard und optional `/mail/health` (authentifiziert).
