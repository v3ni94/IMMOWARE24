#!/usr/bin/env bash
# Immoware Hub, Ersteinrichtung eines frischen dedizierten Servers mit Docker Compose (Variante A).
#
# Zielsystem: IONOS Dedicated Server, Ubuntu 26.04 LTS (resolute), Image "Linux + Docker" (Docker Engine und
# Compose-Plugin vorinstalliert; fehlen sie, installiert das Skript beide aus dem offiziellen Docker-Repository).
# MariaDB und Redis laufen ausschliesslich im internen Docker-Netz ohne veroeffentlichte Ports.
#
# TLS-Terminierung, zwei Betriebsarten (PROXY_MODE):
#   traefik (Standard) Der Server betreibt bereits einen Traefik-Container mit Docker-Provider (exposedbydefault=false),
#                      Entrypoint websecure (:443), Zertifikatsresolver letsencrypt und dem Docker-Netz traefik-proxy.
#                      Der Container "web" wird zusaetzlich in dieses Netz gehaengt, veroeffentlicht keinen Host-Port und
#                      erhaelt Traefik-Labels fuer beide Hostnamen. Kein Host-nginx, kein certbot. Port 80/443 gehoeren Traefik.
#   nginx              Frischer Server ohne Proxy: nginx auf dem Host terminiert TLS (certbot, Webroot) und reicht HTTP an
#                      den Container "web" auf 127.0.0.1:8080 weiter.
#
# Aufruf als root:
#   bash deploy/scripts/server-bootstrap-docker.sh [--dry-run] [--skip-tls] [--skip-deploy]
#   PROXY_MODE=nginx ADMIN_EMAIL=it@example.org bash deploy/scripts/server-bootstrap-docker.sh
#     --dry-run     zeigt alle Schritte, veraendert nichts
#     --skip-tls    nur PROXY_MODE=nginx: kein certbot, Host-nginx bleibt HTTP-only (solange DNS noch nicht auf diesen Server zeigt)
#     --skip-deploy alles einrichten, aber kein docker compose build/up, keine Migration
#     --help        diese Beschreibung
#
# Alle Werte kommen aus den Variablen am Kopf oder aus der Umgebung (VARIABLE=wert bash ...). Das Skript enthaelt keine
# Secrets. Zufaellige Passwoerter landen ausschliesslich in /root/immoware-hub-credentials.txt (0600) und in
# /opt/immoware-hub/.env (0600, Eigentuemer immoware). Das Skript ist idempotent: ein zweiter Lauf ueberschreibt keine
# vorhandenen Secrets, keine vorhandene .env und keine vorhandenen Zertifikate; Migrationen sind idempotent.
#
# Warum Docker statt Host-Installation (deploy/scripts/server-bootstrap.sh): Ubuntu 26.04 liefert PHP 8.5, MariaDB 11.8 und
# Valkey statt Redis. Das Image aus dem Dockerfile bringt die getesteten Versionen (PHP 8.4, MariaDB 11.4, Redis 7)
# unabhaengig von der Paketlage des Releases mit. Leitdokument: docs/operations/06-neuer-server.md, Abschnitt Docker.
#
# ufw und Docker: Docker schreibt eigene iptables-Regeln und umgeht ufw fuer veroeffentlichte Container-Ports. Deshalb
# veroeffentlicht compose.yaml nur den Web-Port und nur an 127.0.0.1 (im Traefik-Modus gar keinen Port); MariaDB und
# Redis bleiben ohne Port-Publish. Andere Compose-Projekte auf demselben Host bleiben unberuehrt, das Skript aendert
# nur die hier genannten Dateien. ufw- und sshd-Aenderungen gelten fuer den ganzen Host (Abschnitt 10 der Doku).
# IONOS-Image: cloud-init (sshd-Drop-in 50-cloud-init.conf), eventuell vorinstalliertes ufw und Docker werden toleriert.
#
# Wiederanlauf: Alle Schritte sind idempotent. Ein Lauf auf einem teilweise eingerichteten Server (Nutzer vorhanden,
# Checkout vorhanden, Verzeichnisse vorhanden) setzt an der jeweiligen Stelle fort, ohne Bestehendes zu ueberschreiben.

set -euo pipefail

# ---------------------------------------------------------------------------
# Variablen (per Umgebung ueberschreibbar)
# ---------------------------------------------------------------------------
HUB_DOMAIN="${HUB_DOMAIN:-immoware.muellerhv.de}"
MAIL_DOMAIN="${MAIL_DOMAIN:-mail.muellerhv.de}"
SERVER_IPV4="${SERVER_IPV4:-82.165.98.36}"
ADMIN_EMAIL="${ADMIN_EMAIL:-immoware@muellerhv.de}"                        # nur PROXY_MODE=nginx: Pflicht fuer certbot (Ablaufhinweise von Let's Encrypt)
PROXY_MODE="${PROXY_MODE:-traefik}"                   # traefik (bestehender Traefik-Container terminiert TLS) oder nginx (Host-nginx + certbot)
TRAEFIK_NETWORK="${TRAEFIK_NETWORK:-traefik-proxy}"   # bestehendes externes Docker-Netz, in dem Traefik seine Backends erreicht
TRAEFIK_ENTRYPOINT="${TRAEFIK_ENTRYPOINT:-websecure}" # Traefik-Entrypoint :443 (web :80 leitet bei Traefik selbst auf websecure um)
TRAEFIK_CERTRESOLVER="${TRAEFIK_CERTRESOLVER:-letsencrypt}"
TRAEFIK_SUBNET=""                                     # wird zur Laufzeit aus docker network inspect ermittelt
REPO_URL="${REPO_URL:-https://github.com/v3ni94/IMMOWARE24}"
BRANCH="${BRANCH:-claude/vibrant-lovelace-c624qw}"
APP_DIR="${APP_DIR:-/opt/immoware-hub}"
DEPLOY_USER="${DEPLOY_USER:-immoware}"
DB_NAME="${DB_NAME:-immoware_hub}"
DB_USER="${DB_USER:-immoware_hub}"
SSH_PUBKEY="${SSH_PUBKEY:-}"                          # optional: zusaetzlicher oeffentlicher Schluessel fuer root
MARIADB_BUFFER_POOL="${MARIADB_BUFFER_POOL:-16G}"
REDIS_MAXMEMORY="${REDIS_MAXMEMORY:-4gb}"
CPU_CORES="$(nproc 2>/dev/null || echo 32)"
PHP_FPM_MAX_CHILDREN="${PHP_FPM_MAX_CHILDREN:-$(( CPU_CORES * 2 ))}"          # 32 Kerne: 64 Kinder, memory_limit 512M je Kind
PHP_FPM_START_SERVERS="${PHP_FPM_START_SERVERS:-$(( CPU_CORES / 4 > 2 ? CPU_CORES / 4 : 2 ))}"
PHP_FPM_MIN_SPARE="${PHP_FPM_MIN_SPARE:-$(( CPU_CORES / 8 > 1 ? CPU_CORES / 8 : 1 ))}"
PHP_FPM_MAX_SPARE="${PHP_FPM_MAX_SPARE:-$(( CPU_CORES / 2 > 4 ? CPU_CORES / 2 : 4 ))}"
DOCKER_SUBNET="${DOCKER_SUBNET:-172.28.0.0/16}"       # festes Subnetz des Compose-Netzes backend, zugleich TRUSTED_PROXIES
WEB_PORT="${WEB_PORT:-8080}"
CLIENT_MAX_BODY_HUB="${CLIENT_MAX_BODY_HUB:-34m}"     # entspricht post_max_size 34M in docker/php/php.ini
CLIENT_MAX_BODY_MAIL="${CLIENT_MAX_BODY_MAIL:-4m}"    # entspricht docker/nginx/mail.muellerhv.de.conf
CREDENTIALS_FILE="${CREDENTIALS_FILE:-/root/immoware-hub-credentials.txt}"
DATA_DIR="$APP_DIR/data"
ETC_DIR="/etc/immoware-hub"
LOG_DIR="/var/log/immoware-hub"
BACKUP_DIR="/var/backups/immoware-hub"
LETSENCRYPT_WEBROOT="/var/www/letsencrypt"
DOCKER_CODENAME="${DOCKER_CODENAME:-}"                # leer: aus /etc/os-release (26.04: resolute)

DRY_RUN=0
SKIP_TLS=0
SKIP_DEPLOY=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --skip-tls) SKIP_TLS=1 ;;
        --skip-deploy) SKIP_DEPLOY=1 ;;
        -h|--help) sed -n '2,/^set -euo pipefail/p' "$0" | sed '$d'; exit 0 ;;
        *) echo "Unbekannte Option: $arg" >&2; exit 2 ;;
    esac
done

export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------------------
# Hilfsfunktionen
# ---------------------------------------------------------------------------
log()  { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
warn() { printf '[%s] HINWEIS: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2; }
fail() { printf '[%s] FEHLER: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2; exit 1; }
step() { printf '\n==> %s\n' "$*"; }

run() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '    [dry-run] %q' "$1"; shift; for a in "$@"; do printf ' %q' "$a"; done; printf '\n'
        return 0
    fi
    "$@"
}

# write_file <pfad> <modus> <eigentuemer:gruppe> : schreibt stdin nach <pfad>, nur wenn sich der Inhalt aendert.
write_file() {
    local path="$1" mode="$2" owner="$3" tmp
    tmp="$(mktemp)"
    cat > "$tmp"
    if [[ -f "$path" ]] && cmp -s "$tmp" "$path"; then
        rm -f "$tmp"; return 0
    fi
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '    [dry-run] schreibe %s (%s, %s)\n' "$path" "$mode" "$owner"
        rm -f "$tmp"; return 0
    fi
    install -D -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$tmp" "$path"
    rm -f "$tmp"
    CHANGED_FILES+=("$path")
}

file_changed() { local f; for f in "${CHANGED_FILES[@]:-}"; do [[ "$f" == "$1" ]] && return 0; done; return 1; }

random_secret() { openssl rand -base64 48 | tr -d '/+=\n' | cut -c1-40; }

credential_get() { [[ -f "$CREDENTIALS_FILE" ]] && sed -n "s/^$1=//p" "$CREDENTIALS_FILE" | head -n1 || true; }

credential_set() {
    [[ "$DRY_RUN" -eq 1 ]] && { printf '    [dry-run] merke %s in %s\n' "$1" "$CREDENTIALS_FILE"; return 0; }
    touch "$CREDENTIALS_FILE"; chmod 600 "$CREDENTIALS_FILE"
    if grep -q "^$1=" "$CREDENTIALS_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$CREDENTIALS_FILE"
    else
        printf '%s=%s\n' "$1" "$2" >> "$CREDENTIALS_FILE"
    fi
}

pkg_installed() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q "install ok installed"; }

# as_deploy <befehl...>: fuehrt einen Befehl als DEPLOY_USER aus (Home gesetzt).
as_deploy() { sudo -u "$DEPLOY_USER" -H "$@"; }

# compose <args...>: docker compose im APP_DIR als DEPLOY_USER (Gruppe docker), nutzt compose.yaml + compose.override.yaml.
compose() { as_deploy docker compose --project-directory "$APP_DIR" "$@"; }

CHANGED_FILES=()
NEXT_STEPS=()
DETECTED=()
note() { DETECTED+=("$*"); log "$*"; }

# ---------------------------------------------------------------------------
# 0. Vorpruefungen
# ---------------------------------------------------------------------------
step "Vorpruefungen"
[[ "$(id -u)" -eq 0 ]] || fail "Bitte als root ausfuehren."
[[ -r /etc/os-release ]] || fail "/etc/os-release fehlt, kein Ubuntu?"
# shellcheck source=/dev/null
. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || fail "Unterstuetzt wird nur Ubuntu (gefunden: ${ID:-unbekannt})."
RELEASE_ID="${VERSION_ID:-unbekannt}"
RELEASE_CODENAME="${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}"
[[ -n "$DOCKER_CODENAME" ]] || DOCKER_CODENAME="$RELEASE_CODENAME"
case "$RELEASE_ID" in
    26.04) note "Release: Ubuntu 26.04 LTS ($RELEASE_CODENAME), Zielstand dieser Variante." ;;
    24.04) note "Release: Ubuntu 24.04 LTS ($RELEASE_CODENAME). Docker-Variante laeuft auch hier, Referenz ist 26.04." ;;
    *) warn "Ubuntu $RELEASE_ID ($RELEASE_CODENAME) ist nicht der dokumentierte Zielstand (26.04). Fortsetzung auf eigenes Risiko." ;;
esac
case "$PROXY_MODE" in
    traefik|nginx) ;;
    *) fail "PROXY_MODE muss traefik oder nginx sein (gefunden: $PROXY_MODE)." ;;
esac
if [[ "$PROXY_MODE" == "traefik" ]]; then
    [[ "$TRAEFIK_NETWORK" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || fail "TRAEFIK_NETWORK ungueltig: $TRAEFIK_NETWORK"
    [[ "$TRAEFIK_ENTRYPOINT" =~ ^[A-Za-z0-9_-]+$ ]] || fail "TRAEFIK_ENTRYPOINT ungueltig: $TRAEFIK_ENTRYPOINT"
    [[ "$TRAEFIK_CERTRESOLVER" =~ ^[A-Za-z0-9_-]+$ ]] || fail "TRAEFIK_CERTRESOLVER ungueltig: $TRAEFIK_CERTRESOLVER"
    if [[ "$SKIP_TLS" -eq 1 ]]; then
        warn "--skip-tls hat im Traefik-Modus keine Wirkung: TLS terminiert der bestehende Traefik-Container. Option wird ignoriert."
        SKIP_TLS=0
    fi
    # Traefik laeuft bereits als Container, Docker muss also vorhanden sein. Das Backend-Netz von Traefik muss existieren,
    # sonst haengt der Container web spaeter in einem Netz, das Traefik nicht sieht (compose wuerde es nicht anlegen: external).
    if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
        if docker network inspect "$TRAEFIK_NETWORK" >/dev/null 2>&1; then
            TRAEFIK_SUBNET="$(docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}' "$TRAEFIK_NETWORK" 2>/dev/null | tr -d '[:space:]')"
            note "Traefik-Netz $TRAEFIK_NETWORK vorhanden, Subnetz ${TRAEFIK_SUBNET:-unbekannt}."
            if ! docker ps --format '{{.Image}}' 2>/dev/null | grep -qi traefik; then
                warn "Kein laufender Container mit Image traefik gefunden. Traefik muss laufen, sonst gibt es fuer $HUB_DOMAIN kein Zertifikat und kein Routing."
            fi
        elif [[ "$DRY_RUN" -eq 1 ]]; then
            warn "Docker-Netz $TRAEFIK_NETWORK existiert nicht. Im echten Lauf Abbruch; Traefik-Netz pruefen (docker network ls) oder TRAEFIK_NETWORK setzen."
        else
            fail "Docker-Netz $TRAEFIK_NETWORK existiert nicht (docker network inspect). Traefik-Netz mit 'docker network ls' ermitteln und TRAEFIK_NETWORK setzen, oder PROXY_MODE=nginx fuer einen Server ohne Traefik verwenden."
        fi
    elif [[ "$DRY_RUN" -eq 1 ]]; then
        warn "Docker nicht verfuegbar, Traefik-Netz $TRAEFIK_NETWORK kann im Dry-Run nicht geprueft werden."
    else
        fail "PROXY_MODE=traefik setzt eine laufende Docker Engine mit dem Traefik-Container voraus (docker info schlaegt fehl)."
    fi
else
    [[ "$SKIP_TLS" -eq 1 || -n "$ADMIN_EMAIL" ]] || fail "ADMIN_EMAIL ist fuer certbot Pflicht (oder --skip-tls verwenden)."
    [[ -z "$ADMIN_EMAIL" || "$ADMIN_EMAIL" == *@*.* ]] || fail "ADMIN_EMAIL sieht nicht wie eine E-Mail-Adresse aus: $ADMIN_EMAIL"
fi
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || fail "DEPLOY_USER ungueltig: $DEPLOY_USER"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB_NAME und DB_USER duerfen nur Buchstaben, Ziffern und Unterstrich enthalten."
[[ "$SERVER_IPV4" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "SERVER_IPV4 ungueltig: $SERVER_IPV4"
[[ "$MARIADB_BUFFER_POOL" =~ ^[0-9]+[MG]$ ]] || fail "MARIADB_BUFFER_POOL ungueltig (z. B. 16G): $MARIADB_BUFFER_POOL"
[[ "$REDIS_MAXMEMORY" =~ ^[0-9]+(mb|gb)$ ]] || fail "REDIS_MAXMEMORY ungueltig (z. B. 4gb): $REDIS_MAXMEMORY"
[[ "$DRY_RUN" -eq 1 ]] && warn "Dry-Run: es wird nichts veraendert."
MEM_TOTAL_GB="$(awk '/MemTotal/{printf "%d", $2/1024/1024}' /proc/meminfo 2>/dev/null || echo 0)"
note "Hardware: $CPU_CORES Kerne, ${MEM_TOTAL_GB} GB RAM. php-fpm: max_children=$PHP_FPM_MAX_CHILDREN, MariaDB Buffer Pool $MARIADB_BUFFER_POOL, Redis maxmemory $REDIS_MAXMEMORY."
if [[ "$MEM_TOTAL_GB" -gt 0 && "$MEM_TOTAL_GB" -lt 24 ]]; then
    warn "Nur ${MEM_TOTAL_GB} GB RAM erkannt. MARIADB_BUFFER_POOL=$MARIADB_BUFFER_POOL und REDIS_MAXMEMORY=$REDIS_MAXMEMORY sind fuer 256 GB ausgelegt, bitte anpassen."
fi
log "Hub: $HUB_DOMAIN, Mail: $MAIL_DOMAIN, IPv4: $SERVER_IPV4, Nutzer: $DEPLOY_USER, App: $APP_DIR, Repo: $REPO_URL ($BRANCH)"
if [[ "$PROXY_MODE" == "traefik" ]]; then
    note "Proxy-Modus: traefik (Netz $TRAEFIK_NETWORK, Entrypoint $TRAEFIK_ENTRYPOINT, Resolver $TRAEFIK_CERTRESOLVER). Kein Host-nginx, kein certbot."
else
    note "Proxy-Modus: nginx (Host-nginx mit certbot, Port 80/443 muessen frei sein)."
fi

# ---------------------------------------------------------------------------
# 1. Basissystem: Pakete, Updates, Zeitzone
# ---------------------------------------------------------------------------
step "1. Basissystem, apt update/upgrade, Zeitzone Europe/Berlin"
if [[ "$DRY_RUN" -eq 0 ]] && command -v cloud-init >/dev/null 2>&1; then
    # IONOS-Image: cloud-init kann beim ersten Boot noch apt sperren. Warten, damit apt nicht kollidiert.
    cloud-init status --wait >/dev/null 2>&1 || warn "cloud-init meldet einen Fehlerstatus, Fortsetzung."
fi
run apt-get update -q
run apt-get -o Dpkg::Options::=--force-confold upgrade -y -q
BASE_PACKAGES=(ca-certificates curl gnupg lsb-release git ufw fail2ban unattended-upgrades apt-listchanges
    dnsutils openssl jq logrotate cron zstd rsync)
if [[ "$PROXY_MODE" == "nginx" ]]; then
    BASE_PACKAGES+=(nginx certbot)
else
    log "Traefik-Modus: nginx und certbot werden nicht installiert (TLS terminiert Traefik)."
fi
run apt-get install -y -q "${BASE_PACKAGES[@]}"
run timedatectl set-timezone Europe/Berlin

write_file /etc/apt/apt.conf.d/20auto-upgrades 0644 root:root <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
write_file /etc/apt/apt.conf.d/52immoware-unattended 0644 root:root <<'EOF'
// Immoware Hub: Sicherheitsupdates automatisch, kein automatischer Neustart (Neustart geplant im Wartungsfenster).
// Docker-Pakete werden nicht automatisch aktualisiert, damit ein Engine-Update nie ungeplant Container neu startet.
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Package-Blacklist {
    "docker-ce";
    "docker-ce-cli";
    "containerd.io";
    "docker-compose-plugin";
    "docker-buildx-plugin";
};
EOF
run systemctl enable --now unattended-upgrades

# ---------------------------------------------------------------------------
# 2. Firewall, fail2ban, sshd
# ---------------------------------------------------------------------------
step "2. ufw (22, 80, 443), fail2ban sshd, sshd-Haertung"
# ufw kann im IONOS-Image bereits aktiv sein; die Befehle sind idempotent ("Skipping adding existing rule").
# Die Regeln gelten fuer den ganzen Host, also auch fuer bereits laufende Anwendungen anderer Compose-Projekte.
warn "ufw: Docker umgeht ufw fuer veroeffentlichte Container-Ports (eigene iptables-Ketten DOCKER, DOCKER-USER). Ports, die andere Container mit -p oder ports: an 0.0.0.0 binden, bleiben trotz 'default deny incoming' von aussen erreichbar. Bestand vorher mit 'docker ps' und 'ss -tlnp' pruefen (docs/operations/06-neuer-server.md, Abschnitt 10.8)."
run ufw default deny incoming
run ufw default allow outgoing
run ufw allow 22/tcp
run ufw allow 80/tcp
run ufw allow 443/tcp
run ufw --force enable

write_file /etc/fail2ban/jail.d/sshd.local 0644 root:root <<'EOF'
[sshd]
enabled = true
backend = systemd
maxretry = 5
findtime = 10m
bantime = 1h
EOF
run systemctl enable --now fail2ban
if [[ "$DRY_RUN" -eq 0 ]] && file_changed /etc/fail2ban/jail.d/sshd.local; then systemctl restart fail2ban; fi

ROOT_KEY_OK=0
if [[ -n "$SSH_PUBKEY" ]]; then
    if printf '%s\n' "$SSH_PUBKEY" | ssh-keygen -l -f /dev/stdin >/dev/null 2>&1; then
        if [[ "$DRY_RUN" -eq 1 ]]; then
            log "[dry-run] SSH_PUBKEY wuerde nach /root/.ssh/authorized_keys uebernommen."
        else
            install -d -m 700 /root/.ssh
            touch /root/.ssh/authorized_keys; chmod 600 /root/.ssh/authorized_keys
            grep -qxF "$SSH_PUBKEY" /root/.ssh/authorized_keys || printf '%s\n' "$SSH_PUBKEY" >> /root/.ssh/authorized_keys
        fi
        ROOT_KEY_OK=1
        log "Admin-Schluessel fuer root hinterlegt."
    else
        warn "SSH_PUBKEY ist kein gueltiger oeffentlicher Schluessel, uebersprungen."
    fi
fi
# IONOS hinterlegt den bei der Bestellung angegebenen Schluessel bereits fuer root. Ist mindestens ein Schluessel
# vorhanden, wird die Passwort-Anmeldung abgeschaltet.
if [[ "$ROOT_KEY_OK" -eq 0 && -s /root/.ssh/authorized_keys ]] && grep -Eq '^(ssh-|ecdsa-|sk-)' /root/.ssh/authorized_keys; then
    ROOT_KEY_OK=1
    log "Vorhandener SSH-Schluessel fuer root erkannt (IONOS-Provisionierung), Passwort-Anmeldung wird abgeschaltet."
fi

# Drop-in mit niedriger Nummer, damit er vor 50-cloud-init.conf gelesen wird (bei sshd gewinnt der erste Wert).
{
    echo "# Immoware Hub, sshd-Haertung (deploy/scripts/server-bootstrap-docker.sh)"
    echo "PermitRootLogin prohibit-password"
    echo "X11Forwarding no"
    echo "MaxAuthTries 4"
    echo "ClientAliveInterval 300"
    echo "ClientAliveCountMax 2"
    if [[ "$ROOT_KEY_OK" -eq 1 ]]; then
        echo "PasswordAuthentication no"
        echo "KbdInteractiveAuthentication no"
    fi
} | write_file /etc/ssh/sshd_config.d/00-immoware-hardening.conf 0644 root:root
[[ "$ROOT_KEY_OK" -eq 1 ]] || warn "Passwort-Anmeldung per SSH bleibt aktiv, weil kein SSH-Schluessel fuer root vorliegt. SSH_PUBKEY setzen und Skript erneut ausfuehren."
if [[ "$DRY_RUN" -eq 0 ]]; then
    sshd -t || fail "sshd-Konfiguration ungueltig, keine Aenderung aktiviert."
    if file_changed /etc/ssh/sshd_config.d/00-immoware-hardening.conf; then
        systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
    fi
fi

# ---------------------------------------------------------------------------
# 3. Docker Engine und Compose-Plugin
# ---------------------------------------------------------------------------
step "3. Docker Engine und Compose-Plugin pruefen, sonst aus download.docker.com installieren"
DOCKER_OK=0
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    DOCKER_OK=1
    note "Docker vorhanden: $(docker --version 2>/dev/null | head -n1), $(docker compose version 2>/dev/null | head -n1)."
    if pkg_installed docker.io; then
        warn "Docker stammt aus dem Ubuntu-Paket docker.io, nicht aus dem offiziellen Repository. Funktioniert, Versionen abweichend."
    fi
fi
if [[ "$DOCKER_OK" -eq 0 ]]; then
    note "Docker fehlt oder ohne Compose-Plugin, Installation aus download.docker.com (Suite $DOCKER_CODENAME)."
    if [[ "$DRY_RUN" -eq 1 ]]; then
        log "[dry-run] wuerde Docker-Signaturschluessel laden, Quelle /etc/apt/sources.list.d/docker.sources ($DOCKER_CODENAME) anlegen und docker-ce, docker-ce-cli, containerd.io, docker-buildx-plugin, docker-compose-plugin installieren."
    else
        install -d -m 755 /etc/apt/keyrings
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
        chmod 644 /etc/apt/keyrings/docker.asc
        # Fingerabdruck des Docker-Release-Schluessels laut docs.docker.com: 9DC8 5822 9FC7 DD38 854A E2D8 8D81 803C 0EBF CD88
        fp="$(gpg --show-keys --with-colons /etc/apt/keyrings/docker.asc 2>/dev/null | awk -F: '/^fpr/{print $10; exit}')"
        [[ "$fp" == "9DC858229FC7DD38854AE2D88D81803C0EBFCD88" ]] || fail "Docker-Signaturschluessel hat unerwarteten Fingerabdruck: $fp"
        if ! curl -fsSI "https://download.docker.com/linux/ubuntu/dists/$DOCKER_CODENAME/Release" >/dev/null 2>&1; then
            fail "download.docker.com kennt die Suite '$DOCKER_CODENAME' (noch) nicht. Docker-Repository fuer Ubuntu $RELEASE_ID pruefen, ggf. DOCKER_CODENAME=noble setzen (Pakete von 24.04 laufen in der Regel) oder das IONOS-Image mit Docker verwenden."
        fi
        write_file /etc/apt/sources.list.d/docker.sources 0644 root:root <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $DOCKER_CODENAME
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
EOF
        apt-get update -q
        apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    fi
fi

# Daemon-Konfiguration: Log-Rotation, live-restore (Container laufen bei Engine-Neustart weiter). Vorhandene Werte des
# IONOS-Images bleiben erhalten, nur diese Schluessel werden gesetzt (Merge per jq).
DAEMON_JSON=/etc/docker/daemon.json
existing='{}'
[[ -s "$DAEMON_JSON" ]] && existing="$(cat "$DAEMON_JSON")"
echo "$existing" | jq -e . >/dev/null 2>&1 || fail "$DAEMON_JSON ist kein gueltiges JSON, bitte manuell pruefen."
echo "$existing" | jq --indent 4 '. + {"log-driver": "json-file", "log-opts": {"max-size": "50m", "max-file": "5"}, "live-restore": true}' \
    | write_file "$DAEMON_JSON" 0644 root:root
run systemctl enable docker
# Traefik-Modus nutzt in compose.override.yaml "ports: !reset []" (Compose ab 2.24, Januar 2024).
if [[ "$PROXY_MODE" == "traefik" ]] && command -v docker >/dev/null 2>&1; then
    compose_ver="$(docker compose version --short 2>/dev/null | sed 's/^v//')"
    if [[ -n "$compose_ver" ]]; then
        if [[ "$(printf '%s\n2.24.0\n' "$compose_ver" | sort -V | head -n1)" != "2.24.0" ]]; then
            fail "Docker Compose $compose_ver ist zu alt fuer '!reset' in compose.override.yaml (mindestens 2.24). docker-compose-plugin aktualisieren."
        fi
        note "Docker Compose $compose_ver unterstuetzt !reset (Traefik-Modus)."
    fi
fi
if [[ "$DRY_RUN" -eq 0 ]]; then
    if file_changed "$DAEMON_JSON"; then
        systemctl restart docker
        log "Docker-Daemon neu gestartet ($DAEMON_JSON geaendert)."
    else
        systemctl start docker
    fi
    docker info >/dev/null 2>&1 || fail "Docker-Daemon antwortet nicht (docker info)."
    if ! docker info 2>/dev/null | grep -q "Live Restore Enabled: true"; then
        warn "live-restore ist laut docker info nicht aktiv, Daemon-Konfiguration pruefen."
    fi
fi

# ---------------------------------------------------------------------------
# 4. Deploy-Nutzer (Gruppe docker) und Deploy-Key fuer git clone
# ---------------------------------------------------------------------------
step "4. Deploy-Nutzer $DEPLOY_USER (Gruppe docker, Passwort gesperrt, nur SSH-Schluessel)"
if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    run useradd --system --create-home --home-dir "/home/$DEPLOY_USER" --shell /bin/bash --user-group "$DEPLOY_USER"
fi
run passwd -l "$DEPLOY_USER"
getent group docker >/dev/null 2>&1 || run groupadd docker
run usermod -aG docker "$DEPLOY_USER"
if [[ "$DRY_RUN" -eq 0 ]]; then
    install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
    touch "/home/$DEPLOY_USER/.ssh/authorized_keys"
    chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"; chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
    if [[ ! -f "/home/$DEPLOY_USER/.ssh/id_ed25519" ]]; then
        as_deploy ssh-keygen -q -t ed25519 -N '' -C "$DEPLOY_USER@$HUB_DOMAIN deploy" -f "/home/$DEPLOY_USER/.ssh/id_ed25519"
        log "Neuer SSH-Schluessel fuer $DEPLOY_USER erzeugt (git clone per Deploy Key)."
    fi
    if [[ "$REPO_URL" == git@github.com:* || "$REPO_URL" == ssh://git@github.com/* ]]; then
        if ! as_deploy ssh-keygen -F github.com -f "/home/$DEPLOY_USER/.ssh/known_hosts" >/dev/null 2>&1; then
            ssh-keyscan -t ed25519,rsa,ecdsa github.com 2>/dev/null | as_deploy tee -a "/home/$DEPLOY_USER/.ssh/known_hosts" >/dev/null || true
            warn "GitHub-Host-Schluessel per ssh-keyscan uebernommen. Fingerabdruck gegen https://docs.github.com/de/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints pruefen."
        fi
    fi
fi
# Hinweis: sudo-Rechte braucht der Deploy-Nutzer nicht. docker compose laeuft ueber die Gruppe docker (entspricht root-Rechten
# auf dem Host, deshalb nur dieser eine Nutzer). Host-nginx, certbot und Backup bleiben bei root.

# ---------------------------------------------------------------------------
# 5. Repository nach APP_DIR (Clone oder Update), Datenverzeichnisse
# ---------------------------------------------------------------------------
step "5. Repository $REPO_URL ($BRANCH) nach $APP_DIR"
run install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_DIR"
repo_reachable() { as_deploy git ls-remote --exit-code --heads "$REPO_URL" "$BRANCH" >/dev/null 2>&1; }
if [[ "$DRY_RUN" -eq 0 ]]; then
    if ! repo_reachable; then
        echo
        echo "Das Repository $REPO_URL ($BRANCH) ist fuer $DEPLOY_USER nicht erreichbar."
        echo "Bei einem privaten GitHub-Repository diesen oeffentlichen Schluessel als Deploy Key (nur Lesen) hinterlegen"
        echo "(Repository, Settings, Deploy keys). Ein Deploy Key funktioniert nur mit der SSH-URL, dann zusaetzlich"
        echo "REPO_URL=git@github.com:<owner>/<repo>.git setzen."
        echo
        cat "/home/$DEPLOY_USER/.ssh/id_ed25519.pub"
        echo
        if [[ -t 0 ]]; then
            read -r -p "Weiter mit Enter, sobald der Deploy Key hinterlegt ist (Strg+C bricht ab) ... " _
            repo_reachable || fail "Repository weiterhin nicht erreichbar. Deploy Key, REPO_URL und BRANCH pruefen, Skript erneut starten."
        else
            fail "Repository nicht erreichbar (keine interaktive Sitzung). Deploy Key hinterlegen und Skript erneut starten."
        fi
    fi
    if [[ -d "$APP_DIR/.git" ]]; then
        log "Checkout vorhanden, aktualisiere auf origin/$BRANCH."
        as_deploy git -C "$APP_DIR" remote set-url origin "$REPO_URL"
        as_deploy git -C "$APP_DIR" fetch --quiet origin "$BRANCH"
        as_deploy git -C "$APP_DIR" checkout --quiet -B "$BRANCH" FETCH_HEAD
    elif [[ -z "$(ls -A "$APP_DIR")" ]]; then
        as_deploy git clone --quiet --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
    else
        fail "$APP_DIR ist nicht leer und kein Git-Checkout. Verzeichnis leeren oder APP_DIR aendern."
    fi
    for f in compose.yaml Dockerfile .env.example docker/nginx/"$HUB_DOMAIN".conf docker/nginx/"$MAIL_DOMAIN".conf deploy/scripts/backup-docker.sh; do
        [[ -f "$APP_DIR/$f" ]] || fail "Datei fehlt im Checkout: $f (Domains muessen zu den Dateinamen in docker/nginx passen)."
    done
    GIT_SHA="$(as_deploy git -C "$APP_DIR" rev-parse --short HEAD)"
    # Lokale Betriebsdateien nicht als Aenderung im Checkout fuehren
    for p in data/ compose.override.yaml .env; do
        grep -qxF "$p" "$APP_DIR/.git/info/exclude" 2>/dev/null || echo "$p" >> "$APP_DIR/.git/info/exclude"
    done
else
    GIT_SHA="dryrun"
    log "[dry-run] wuerde Erreichbarkeit pruefen und $REPO_URL ($BRANCH) nach $APP_DIR klonen oder aktualisieren."
fi
note "Stand: $BRANCH @ $GIT_SHA"

step "5b. Datenverzeichnisse unter $DATA_DIR, $ETC_DIR, $LOG_DIR, $BACKUP_DIR"
# Bind-Quellen der Compose-Volumes. Eigentuemer entsprechen den Container-Nutzern: MariaDB und Redis (999) chownen selbst,
# storage und imports gehoeren www-data des Alpine-Images (uid 82), nicht www-data des Hosts (uid 33).
# install -o/-g akzeptiert nur Namen, die auf dem Host existieren ("install: invalid user: '82'"). Die Container-UIDs
# 999 und 82 gibt es auf dem Host nicht, deshalb Verzeichnis ohne Eigentuemer anlegen und numerisch chownen.
run install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$DATA_DIR"
run install -d -m 750 "$DATA_DIR/mariadb" "$DATA_DIR/redis"
run chown 999:999 "$DATA_DIR/mariadb" "$DATA_DIR/redis"
run install -d -m 775 "$DATA_DIR/storage" "$DATA_DIR/imports"
run chown 82:82 "$DATA_DIR/storage" "$DATA_DIR/imports"
run install -d -m 750 -o root -g root "$ETC_DIR"
run install -d -m 750 -o root -g adm "$LOG_DIR"
run install -d -m 700 -o root -g root "$BACKUP_DIR"
if [[ "$PROXY_MODE" == "nginx" ]]; then
    run install -d -m 750 -o root -g adm "$LOG_DIR/nginx"
    run install -d -m 755 -o www-data -g www-data "$LETSENCRYPT_WEBROOT"
fi

# ---------------------------------------------------------------------------
# 6. Zugangsdaten und .env
# ---------------------------------------------------------------------------
step "6. Zugangsdaten ($CREDENTIALS_FILE) und $APP_DIR/.env"
DB_PASSWORD="$(credential_get DB_PASSWORD)"
[[ -n "$DB_PASSWORD" ]] || { DB_PASSWORD="$(random_secret)"; credential_set DB_PASSWORD "$DB_PASSWORD"; }
DB_ROOT_PASSWORD="$(credential_get DB_ROOT_PASSWORD)"
[[ -n "$DB_ROOT_PASSWORD" ]] || { DB_ROOT_PASSWORD="$(random_secret)"; credential_set DB_ROOT_PASSWORD "$DB_ROOT_PASSWORD"; }
REDIS_PASSWORD="$(credential_get REDIS_PASSWORD)"
[[ -n "$REDIS_PASSWORD" ]] || { REDIS_PASSWORD="$(random_secret)"; credential_set REDIS_PASSWORD "$REDIS_PASSWORD"; }
credential_set DB_DATABASE "$DB_NAME"
credential_set DB_USERNAME "$DB_USER"

# TRUSTED_PROXIES: php-fpm sieht als Gegenstelle immer den Container web (Netz backend, DOCKER_SUBNET). Im Traefik-Modus
# kommt das Subnetz des Traefik-Netzes hinzu, damit die Kette Traefik -> web -> app vollstaendig als vertrauenswuerdig
# gilt. bootstrap/app.php verwirft '*' ausdruecklich (dann wuerde kein X-Forwarded-Header ausgewertet), deshalb ist der
# Fallback bei unbekanntem Traefik-Subnetz DOCKER_SUBNET allein, nicht '*'.
TRUSTED_PROXIES_VALUE="$DOCKER_SUBNET"
if [[ "$PROXY_MODE" == "traefik" ]]; then
    if [[ -n "$TRAEFIK_SUBNET" ]]; then
        TRUSTED_PROXIES_VALUE="$DOCKER_SUBNET,$TRAEFIK_SUBNET"
    else
        warn "Subnetz des Traefik-Netzes $TRAEFIK_NETWORK konnte nicht ermittelt werden. TRUSTED_PROXIES=$DOCKER_SUBNET (nur Container web). Nach dem Lauf pruefen: docker network inspect $TRAEFIK_NETWORK, Wert in $APP_DIR/.env ergaenzen."
    fi
fi

ENV_FILE="$APP_DIR/.env"
if [[ -f "$ENV_FILE" ]]; then
    log ".env vorhanden, wird nicht veraendert (Secrets bleiben erhalten). Nur IMAGE_TAG wird auf $GIT_SHA gesetzt."
    [[ "$DRY_RUN" -eq 1 ]] || sed -i "s|^IMAGE_TAG=.*|IMAGE_TAG=$GIT_SHA|" "$ENV_FILE"
    current_tp="$(sed -n 's/^TRUSTED_PROXIES=//p' "$ENV_FILE" | head -n1)"
    if [[ "$current_tp" != "$TRUSTED_PROXIES_VALUE" ]]; then
        warn "TRUSTED_PROXIES in .env ist '${current_tp:-leer}', erwartet fuer PROXY_MODE=$PROXY_MODE: '$TRUSTED_PROXIES_VALUE'. Bei Bedarf manuell angleichen (.env wird nicht automatisch geaendert)."
    fi
elif [[ "$DRY_RUN" -eq 1 ]]; then
    log "[dry-run] wuerde $ENV_FILE aus .env.example erzeugen (production, DB, Redis, APP_KEY, HUB_HASH_PEPPER, TRUSTED_PROXIES=$TRUSTED_PROXIES_VALUE, alle Flags false)."
else
    tmpenv="$(mktemp)"
    cp "$APP_DIR/.env.example" "$tmpenv"
    env_set() {
        if grep -q "^$1=" "$tmpenv"; then
            sed -i "s|^$1=.*|$1=$2|" "$tmpenv"
        else
            printf '\n%s=%s\n' "$1" "$2" >> "$tmpenv"
        fi
    }
    APP_KEY="base64:$(openssl rand -base64 32)"           # gleiches Format wie php artisan key:generate (32 Byte, AES-256)
    HUB_HASH_PEPPER="$(openssl rand -hex 32)"
    credential_set APP_KEY "$APP_KEY"
    credential_set HUB_HASH_PEPPER "$HUB_HASH_PEPPER"
    env_set APP_ENV production
    env_set APP_DEBUG false
    env_set APP_KEY "$APP_KEY"
    env_set APP_URL "https://$HUB_DOMAIN"
    env_set LOG_CHANNEL stderr
    env_set LOG_LEVEL info
    env_set DB_CONNECTION mariadb
    env_set DB_HOST mariadb
    env_set DB_PORT 3306
    env_set DB_DATABASE "$DB_NAME"
    env_set DB_USERNAME "$DB_USER"
    env_set DB_PASSWORD "$DB_PASSWORD"
    env_set REDIS_HOST redis
    env_set REDIS_PORT 6379
    env_set REDIS_PASSWORD "$REDIS_PASSWORD"
    env_set QUEUE_CONNECTION redis
    env_set CACHE_STORE redis
    env_set SESSION_DRIVER redis
    env_set REDIS_QUEUE_RETRY_AFTER 3600
    env_set SESSION_SECURE_COOKIE true
    env_set TRUSTED_PROXIES "$TRUSTED_PROXIES_VALUE"
    env_set HUB_HASH_PEPPER "$HUB_HASH_PEPPER"
    env_set HUB_DB_TRIGGERS true
    env_set MAIL_APP_DOMAIN "$MAIL_DOMAIN"
    for flag in IMMOWARE_WRITE_ENABLED IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED IMMOWARE_WRITE_WEBDAV_OVERWRITE_ENABLED \
                IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED IMMOWARE_WRITE_WEBDAV_MOVE_ENABLED IMMOWARE_WRITE_CARDDAV_ENABLED \
                IMMOWARE_WRITE_CALDAV_ENABLED IMMOWARE_WRITE_DRY_RUN HUB_WEBHOOKS_ENABLED HUB_API_KEYS_ENABLED \
                MAIL_IMPORT_ENABLED MAIL_AI_ENABLED MAIL_GMAIL_DRAFTS_ENABLED MAIL_GMAIL_SEND_ENABLED \
                MAIL_IMMOWARE_WRITE_ENABLED MAIL_LEXWARE_WRITE_ENABLED; do
        env_set "$flag" false
    done
    cat >> "$tmpenv" <<EOF

# Docker Compose (server-bootstrap-docker.sh). DB_ROOT_PASSWORD nur fuer den MariaDB-Container, IMAGE_TAG fuer Rollback.
# WEB_BIND/WEB_PORT wirken nur in PROXY_MODE=nginx; im Traefik-Modus veroeffentlicht web keinen Host-Port (compose.override.yaml).
DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD
IMAGE_TAG=$GIT_SHA
WEB_BIND=127.0.0.1
WEB_PORT=$WEB_PORT
PROXY_MODE=$PROXY_MODE
EOF
    install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$tmpenv" "$ENV_FILE"
    rm -f "$tmpenv"
    log ".env erzeugt. APP_KEY und HUB_HASH_PEPPER wurden einmalig gesetzt; php artisan key:generate darf danach nicht mehr laufen."
fi

# ---------------------------------------------------------------------------
# 7. compose.override.yaml und php-fpm Pool fuer den Server
# ---------------------------------------------------------------------------
step "7. $APP_DIR/compose.override.yaml (Bind-Volumes, Buffer Pool, maxmemory, festes Subnetz), php-fpm Pool"
write_file "$ETC_DIR/php-fpm-pool.conf" 0644 root:root <<EOF
; Immoware Hub, php-fpm Pool fuer diesen Server (server-bootstrap-docker.sh, $CPU_CORES Kerne).
; Ueberschreibt docker/php/www.conf im Container app (Mount in compose.override.yaml). memory_limit 512M je Kind.
[www]
user = www-data
group = www-data
listen = 9000
pm = dynamic
pm.max_children = $PHP_FPM_MAX_CHILDREN
pm.start_servers = $PHP_FPM_START_SERVERS
pm.min_spare_servers = $PHP_FPM_MIN_SPARE
pm.max_spare_servers = $PHP_FPM_MAX_SPARE
pm.max_requests = 500
pm.status_path = /fpm-status
ping.path = /fpm-ping
request_terminate_timeout = 120s
clear_env = no
catch_workers_output = yes
decorate_workers_output = no
access.log = /proc/self/fd/2
EOF

# Service web je Proxy-Modus. Traefik: kein Host-Port (ports per !reset geleert, Compose >= 2.24), zusaetzlich im externen
# Traefik-Netz, Labels fuer beide Hostnamen auf einen Traefik-Service, der den Container-Port 8080 anspricht (listen 8080
# in docker/nginx/*.conf; WEB_PORT ist nur der Host-Port des nginx-Modus). HSTS setzt sonst der Host-nginx, hier eine
# Traefik-Middleware. X-Forwarded-Proto/-For setzt Traefik selbst.
render_web_override() {
    if [[ "$PROXY_MODE" == "traefik" ]]; then
        cat <<EOF
    web:
        ports: !reset []
        networks:
            - backend
            - $TRAEFIK_NETWORK
        labels:
            traefik.enable: "true"
            traefik.docker.network: "$TRAEFIK_NETWORK"
            traefik.http.routers.immoware-hub.rule: "Host(\`$HUB_DOMAIN\`)"
            traefik.http.routers.immoware-hub.entrypoints: "$TRAEFIK_ENTRYPOINT"
            traefik.http.routers.immoware-hub.tls.certresolver: "$TRAEFIK_CERTRESOLVER"
            traefik.http.routers.immoware-hub.service: "immoware-hub"
            traefik.http.routers.immoware-hub.middlewares: "immoware-hub-hsts"
            traefik.http.routers.immoware-mail.rule: "Host(\`$MAIL_DOMAIN\`)"
            traefik.http.routers.immoware-mail.entrypoints: "$TRAEFIK_ENTRYPOINT"
            traefik.http.routers.immoware-mail.tls.certresolver: "$TRAEFIK_CERTRESOLVER"
            traefik.http.routers.immoware-mail.service: "immoware-hub"
            traefik.http.routers.immoware-mail.middlewares: "immoware-hub-hsts"
            traefik.http.services.immoware-hub.loadbalancer.server.port: "8080"
            traefik.http.middlewares.immoware-hub-hsts.headers.stsSeconds: "31536000"
            traefik.http.middlewares.immoware-hub-hsts.headers.stsIncludeSubdomains: "true"

EOF
    fi
}

render_networks_override() {
    cat <<EOF
# Festes Subnetz, damit TRUSTED_PROXIES in .env ($DOCKER_SUBNET) zuverlaessig die Adresse des Containers web abdeckt.
networks:
    backend:
        driver: bridge
        ipam:
            config:
                - subnet: $DOCKER_SUBNET
EOF
    if [[ "$PROXY_MODE" == "traefik" ]]; then
        cat <<EOF
    # Bestehendes Netz des Traefik-Containers (nicht von diesem Projekt verwaltet, external).
    $TRAEFIK_NETWORK:
        external: true
EOF
    fi
}

if [[ "$PROXY_MODE" == "traefik" ]]; then
    WEB_MODE_NOTE="Traefik-Modus: web veroeffentlicht keinen Host-Port, Traefik ($TRAEFIK_NETWORK) erreicht den Container-nginx auf Port 8080
# und terminiert TLS fuer $HUB_DOMAIN und $MAIL_DOMAIN (Entrypoint $TRAEFIK_ENTRYPOINT, Resolver $TRAEFIK_CERTRESOLVER)."
else
    WEB_MODE_NOTE="nginx-Modus: compose.yaml bindet ueber WEB_BIND/WEB_PORT aus .env an 127.0.0.1:$WEB_PORT; beide Hostnamen laufen ueber
# denselben Port, der Container-nginx unterscheidet per server_name."
fi

{
cat <<EOF
# Immoware Hub, serverspezifische Ergaenzung zu compose.yaml (erzeugt von deploy/scripts/server-bootstrap-docker.sh,
# PROXY_MODE=$PROXY_MODE). Nicht im Repository. Keine Secrets: Passwoerter kommen aus .env.
# $WEB_MODE_NOTE

services:
    app:
        volumes:
            - $ETC_DIR/php-fpm-pool.conf:/usr/local/etc/php-fpm.d/zz-immoware.conf:ro

EOF
render_web_override
cat <<EOF
    mariadb:
        command:
            - --character-set-server=utf8mb4
            - --collation-server=utf8mb4_unicode_ci
            - --innodb-buffer-pool-size=$MARIADB_BUFFER_POOL
            - --innodb-log-file-size=2G
            - --log-bin=mariadb-bin
            - --binlog-format=ROW
            - --expire-logs-days=7
            - --log-bin-trust-function-creators=1
            - --max-connections=300
        restart: unless-stopped

    redis:
        command:
            - redis-server
            - --appendonly
            - "yes"
            - --appendfsync
            - everysec
            - --maxmemory
            - $REDIS_MAXMEMORY
            - --maxmemory-policy
            - noeviction
            - --requirepass
            - \${REDIS_PASSWORD:?REDIS_PASSWORD fehlt in .env}
        restart: unless-stopped

# Datenverzeichnisse auf dem RAID unter $DATA_DIR statt anonymer Docker-Volumes (Backup, Uebersicht).
volumes:
    mariadb:
        driver: local
        driver_opts:
            type: none
            o: bind
            device: $DATA_DIR/mariadb
    redis:
        driver: local
        driver_opts:
            type: none
            o: bind
            device: $DATA_DIR/redis
    storage:
        driver: local
        driver_opts:
            type: none
            o: bind
            device: $DATA_DIR/storage
    imports:
        driver: local
        driver_opts:
            type: none
            o: bind
            device: $DATA_DIR/imports

EOF
render_networks_override
} | write_file "$APP_DIR/compose.override.yaml" 0640 "$DEPLOY_USER:$DEPLOY_USER"

HUB_SITE="/etc/nginx/sites-available/$HUB_DOMAIN.conf"
MAIL_SITE="/etc/nginx/sites-available/$MAIL_DOMAIN.conf"
HUB_CERT="/etc/letsencrypt/live/$HUB_DOMAIN/fullchain.pem"
MAIL_CERT="/etc/letsencrypt/live/$MAIL_DOMAIN/fullchain.pem"

if [[ "$PROXY_MODE" == "traefik" ]]; then
step "8. und 9. entfallen im Traefik-Modus (kein Host-nginx, kein certbot). DNS-Pruefung"
if [[ "$DRY_RUN" -eq 1 ]]; then
    log "[dry-run] wuerde DNS (A = $SERVER_IPV4) fuer $HUB_DOMAIN und $MAIL_DOMAIN pruefen; Zertifikate stellt Traefik ($TRAEFIK_CERTRESOLVER) beim ersten Aufruf aus."
else
    for domain in "$HUB_DOMAIN" "$MAIL_DOMAIN"; do
        a_records="$(dig +short A "$domain" @1.1.1.1 | tr '\n' ' ')"
        if [[ " $a_records" != *" $SERVER_IPV4 "* ]]; then
            warn "DNS: A-Record von $domain ist '${a_records:-leer}', erwartet $SERVER_IPV4. Traefik kann ohne passenden A-Record kein Zertifikat (HTTP-Challenge) ausstellen."
            NEXT_STEPS+=("DNS: A-Record $domain auf $SERVER_IPV4 setzen, danach stellt Traefik das Zertifikat automatisch aus (docker logs des Traefik-Containers pruefen).")
        else
            log "DNS ok: $domain -> $a_records"
        fi
    done
fi
if [[ -d /etc/nginx/sites-enabled || -d /etc/letsencrypt/live ]]; then
    warn "Reste eines Host-nginx oder certbot gefunden (/etc/nginx bzw. /etc/letsencrypt). Im Traefik-Modus werden sie nicht angefasst; ein Host-nginx darf Port 80/443 nicht belegen."
fi
else

# ---------------------------------------------------------------------------
# 8. Host-nginx als TLS-Proxy (zunaechst HTTP-only mit ACME-Webroot)
# ---------------------------------------------------------------------------
step "8. Host-nginx (Reverse Proxy auf 127.0.0.1:$WEB_PORT)"
[[ -e /etc/nginx/sites-enabled/default ]] && run rm -f /etc/nginx/sites-enabled/default

write_file /etc/nginx/conf.d/immoware-upstream.conf 0644 root:root <<EOF
# Immoware Hub, Upstream zum Container web (server-bootstrap-docker.sh)
upstream immoware_web {
    server 127.0.0.1:$WEB_PORT;
    keepalive 32;
}
EOF

# proxy_snippet: gemeinsame Proxy-Header fuer beide Domains
write_file /etc/nginx/snippets/immoware-proxy.conf 0644 root:root <<'EOF'
# Immoware Hub, Weitergabe an den Container web. Die Anwendung wertet X-Forwarded-* nur von TRUSTED_PROXIES aus
# (Docker-Subnetz, also der Container web), der Container-nginx setzt HTTPS anhand von X-Forwarded-Proto.
proxy_http_version 1.1;
proxy_set_header Connection "";
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header X-Forwarded-Host $host;
proxy_set_header X-Forwarded-Port $server_port;
proxy_read_timeout 120s;
proxy_send_timeout 120s;
proxy_connect_timeout 5s;
proxy_buffering on;
proxy_buffers 16 16k;
proxy_buffer_size 32k;
proxy_pass http://immoware_web;
EOF

# write_http_only_site <domain> <datei> <default_server 1|0> <client_max_body_size>
write_http_only_site() {
    local domain="$1" file="$2" is_default="$3" max_body="$4" default=""
    [[ "$is_default" -eq 1 ]] && default=" default_server"
    write_file "$file" 0644 root:root <<EOF
# Immoware Hub, HTTP-only Uebergangskonfiguration fuer $domain (server-bootstrap-docker.sh). Wird nach certbot durch
# den TLS-Block ersetzt. Der Hub-Block ist default_server, damit der Health-Check ueber 127.0.0.1 funktioniert.
server {
    listen 80$default;
    server_name $domain;
    server_tokens off;
    client_max_body_size $max_body;
    access_log $LOG_DIR/nginx/$domain.access.log;
    error_log  $LOG_DIR/nginx/$domain.error.log warn;
    location /.well-known/acme-challenge/ { root $LETSENCRYPT_WEBROOT; }
    location / { include snippets/immoware-proxy.conf; }
}
EOF
}

# write_tls_site <domain> <datei> <default_server 1|0> <client_max_body_size>
write_tls_site() {
    local domain="$1" file="$2" is_default="$3" max_body="$4" default=""
    [[ "$is_default" -eq 1 ]] && default=" default_server"
    write_file "$file" 0644 root:root <<EOF
# Immoware Hub, TLS-Proxy fuer $domain (server-bootstrap-docker.sh). Zertifikat: Let's Encrypt, certbot certonly --webroot.
# TLS endet hier, der Container web (127.0.0.1:$WEB_PORT) erhaelt Klartext-HTTP mit X-Forwarded-Proto https.
server {
    listen 80$default;
    server_name $domain;
    server_tokens off;
    access_log $LOG_DIR/nginx/$domain.access.log;
    error_log  $LOG_DIR/nginx/$domain.error.log warn;
    location /.well-known/acme-challenge/ { root $LETSENCRYPT_WEBROOT; }
    location / { return 301 https://\$host\$request_uri; }
}

server {
    listen 443 ssl$default;
    http2 on;
    server_name $domain;

    ssl_certificate     /etc/letsencrypt/live/$domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$domain/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_timeout 1d;
    ssl_session_cache shared:immoware_ssl_${domain//[.-]/_}:10m;
    ssl_session_tickets off;
    ssl_stapling on;
    ssl_stapling_verify on;

    server_tokens off;
    client_max_body_size $max_body;
    client_body_timeout 60s;

    # HSTS setzt nur der TLS-Proxy; die uebrigen Sicherheitsheader setzt nginx im Container.
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    access_log $LOG_DIR/nginx/$domain.access.log;
    error_log  $LOG_DIR/nginx/$domain.error.log warn;

    location ~ ^/(health|up)(/|\$) { access_log off; include snippets/immoware-proxy.conf; }
    location / { include snippets/immoware-proxy.conf; }
}
EOF
}

install_tls_sites() {
    write_tls_site "$HUB_DOMAIN" "$HUB_SITE" 1 "$CLIENT_MAX_BODY_HUB"
    write_tls_site "$MAIL_DOMAIN" "$MAIL_SITE" 0 "$CLIENT_MAX_BODY_MAIL"
}

if [[ -f "$HUB_CERT" && -f "$MAIL_CERT" && "$DRY_RUN" -eq 0 ]]; then
    log "Zertifikate vorhanden, TLS-Server-Bloecke installieren."
    install_tls_sites
else
    write_http_only_site "$HUB_DOMAIN" "$HUB_SITE" 1 "$CLIENT_MAX_BODY_HUB"
    write_http_only_site "$MAIL_DOMAIN" "$MAIL_SITE" 0 "$CLIENT_MAX_BODY_MAIL"
fi
run ln -sfn "$HUB_SITE" "/etc/nginx/sites-enabled/$HUB_DOMAIN.conf"
run ln -sfn "$MAIL_SITE" "/etc/nginx/sites-enabled/$MAIL_DOMAIN.conf"
run systemctl enable nginx
if [[ "$DRY_RUN" -eq 0 ]]; then
    nginx -t >/dev/null 2>&1 || { nginx -t; fail "nginx-Konfiguration ungueltig."; }
    systemctl restart nginx
fi

# ---------------------------------------------------------------------------
# 9. TLS mit certbot (DNS-Pruefung vorab)
# ---------------------------------------------------------------------------
step "9. TLS (certbot certonly --webroot)"
if [[ "$SKIP_TLS" -eq 1 ]]; then
    warn "--skip-tls: Host-nginx bleibt HTTP-only. Anmeldung funktioniert erst mit TLS (SESSION_SECURE_COOKIE=true). Spaeter: Skript ohne --skip-tls erneut ausfuehren."
    NEXT_STEPS+=("TLS nachholen: ADMIN_EMAIL=... bash $0 (ohne --skip-tls), sobald DNS auf $SERVER_IPV4 zeigt.")
elif [[ "$DRY_RUN" -eq 1 ]]; then
    log "[dry-run] wuerde DNS (A = $SERVER_IPV4, AAAA darf fehlen) pruefen und certbot certonly --webroot fuer $HUB_DOMAIN und $MAIL_DOMAIN ausfuehren."
else
    SERVER_IPV6="$(ip -6 addr show scope global 2>/dev/null | awk '/inet6/{sub(/\/.*/,"",$2); print $2; exit}')"
    for domain in "$HUB_DOMAIN" "$MAIL_DOMAIN"; do
        a_records="$(dig +short A "$domain" @1.1.1.1 | tr '\n' ' ')"
        aaaa_records="$(dig +short AAAA "$domain" @1.1.1.1 | tr '\n' ' ')"
        if [[ " $a_records" != *" $SERVER_IPV4 "* ]]; then
            fail "DNS: A-Record von $domain ist '${a_records:-leer}', erwartet $SERVER_IPV4. DNS umstellen, TTL abwarten oder --skip-tls verwenden."
        fi
        if [[ -n "$aaaa_records" && ( -z "$SERVER_IPV6" || " $aaaa_records" != *" $SERVER_IPV6 "* ) ]]; then
            fail "DNS: AAAA-Record von $domain ist '$aaaa_records', der Server hat aber '${SERVER_IPV6:-keine globale IPv6}'. Let's Encrypt prueft bevorzugt IPv6. AAAA entfernen (Server ohne IPv6)."
        fi
        log "DNS ok: $domain -> $a_records${aaaa_records:+/ $aaaa_records}"
    done
    for domain in "$HUB_DOMAIN" "$MAIL_DOMAIN"; do
        if [[ -f "/etc/letsencrypt/live/$domain/fullchain.pem" ]]; then
            log "Zertifikat fuer $domain vorhanden."
            continue
        fi
        certbot certonly --non-interactive --agree-tos --no-eff-email -m "$ADMIN_EMAIL" \
            --webroot -w "$LETSENCRYPT_WEBROOT" -d "$domain" --key-type ecdsa \
            || fail "certbot fuer $domain fehlgeschlagen (Port 80 von aussen erreichbar? DNS propagiert?)."
    done
    write_file /etc/letsencrypt/renewal-hooks/deploy/immoware-nginx-reload.sh 0755 root:root <<'EOF'
#!/usr/bin/env bash
# Nach jeder Zertifikatsverlaengerung den Host-nginx neu laden (TLS endet auf dem Host, nicht im Container).
systemctl reload nginx
EOF
    systemctl enable --now certbot.timer >/dev/null 2>&1 || true
    install_tls_sites
    nginx -t >/dev/null 2>&1 || { nginx -t; fail "nginx-Konfiguration (TLS) ungueltig."; }
    systemctl reload nginx
    log "TLS aktiv fuer $HUB_DOMAIN und $MAIL_DOMAIN."
fi

fi # PROXY_MODE

# ---------------------------------------------------------------------------
# 10. logrotate (Host-nginx nur im nginx-Modus) und Backup-Cron
# ---------------------------------------------------------------------------
step "10. logrotate fuer $LOG_DIR und Backup-Cron (taeglich 02:00)"
# Container-Logs rotiert der Docker-Daemon (json-file 50m x 5). Hier nur Host-nginx (nginx-Modus) und Backup-Log.
{
cat <<EOF
# Immoware Hub, $( [[ "$PROXY_MODE" == "nginx" ]] && echo "Host-nginx (TLS-Proxy) und " )Backup-Log (server-bootstrap-docker.sh, PROXY_MODE=$PROXY_MODE)
EOF
[[ "$PROXY_MODE" == "nginx" ]] && cat <<EOF
$LOG_DIR/nginx/*.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    dateext
    create 0640 www-data adm
    sharedscripts
    postrotate
        [ -s /run/nginx.pid ] && kill -USR1 \$(cat /run/nginx.pid) || true
    endscript
}
EOF
cat <<EOF
$LOG_DIR/backup.log {
    weekly
    rotate 12
    missingok
    notifempty
    compress
    delaycompress
    create 0640 root adm
}
EOF
} | write_file /etc/logrotate.d/immoware-hub 0644 root:root
if [[ ! -f "$ETC_DIR/backup.env" ]]; then
    write_file "$ETC_DIR/backup.env" 0600 root:root <<EOF
# Immoware Hub, Umgebung fuer deploy/scripts/backup-docker.sh (docs/operations/02-backup-restore.md Abschnitt 3).
# BACKUP_GPG_RECIPIENT ist Pflicht: Key-ID oder E-Mail des oeffentlichen GPG-Schluessels im Schluesselbund von root.
# Der private Schluessel liegt NICHT auf dem Server. Ohne diesen Wert bricht das Backup ab (Cron-Log $LOG_DIR/backup.log).
BACKUP_GPG_RECIPIENT=
APP_DIR=$APP_DIR
BACKUP_DIR=$BACKUP_DIR
BACKUP_RETENTION_DAYS=35
BACKUP_STORAGE_DIR=$DATA_DIR/storage/app
# Offsite-Kopie, z. B. rclone copy {} remote:immoware-hub/  (leer = nur lokal)
BACKUP_OFFSITE_CMD=
EOF
else
    log "$ETC_DIR/backup.env vorhanden, unveraendert."
fi
write_file /etc/cron.d/immoware-hub-backup 0644 root:root <<EOF
# Immoware Hub, taegliches Backup 02:00 (Europe/Berlin, Systemzeitzone) ueber docker compose exec mariadb.
# Voraussetzung: BACKUP_GPG_RECIPIENT in $ETC_DIR/backup.env und der oeffentliche GPG-Schluessel im Schluesselbund von root.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
0 2 * * * root . $ETC_DIR/backup.env && $APP_DIR/deploy/scripts/backup-docker.sh >> $LOG_DIR/backup.log 2>&1
EOF
NEXT_STEPS+=("Backup: oeffentlichen GPG-Schluessel als root importieren (gpg --import backup-public.asc), BACKUP_GPG_RECIPIENT in $ETC_DIR/backup.env setzen, Probelauf: bash -c '. $ETC_DIR/backup.env && $APP_DIR/deploy/scripts/backup-docker.sh'.")

# ---------------------------------------------------------------------------
# 11. Build, Start, Migration, Doctor, Health
# ---------------------------------------------------------------------------
step "11. docker compose build und up -d, Healthchecks, migrate, hub:doctor, Health-Check"
if [[ "$SKIP_TLS" -eq 1 ]]; then
    HEALTH_URL="http://127.0.0.1/health"
else
    HEALTH_URL="https://$HUB_DOMAIN/health"
fi
if [[ "$SKIP_DEPLOY" -eq 1 ]]; then
    warn "--skip-deploy: kein Build, kein Start, keine Migration."
    NEXT_STEPS+=("Start: sudo -u $DEPLOY_USER -H docker compose --project-directory $APP_DIR up -d --build, danach docker compose exec -T app php artisan migrate --force")
elif [[ "$DRY_RUN" -eq 1 ]]; then
    log "[dry-run] wuerde ausfuehren: docker compose config -q, build, up -d, auf Healthchecks warten, exec app php artisan migrate --force, hub:doctor, curl $HEALTH_URL"
else
    compose config -q || fail "compose.yaml plus compose.override.yaml ungueltig (docker compose config)."
    log "Build des Images immoware-hub:$GIT_SHA (Composer im Build, dauert beim ersten Mal einige Minuten)."
    compose build --pull app || fail "docker compose build fehlgeschlagen."
    compose up -d --remove-orphans || fail "docker compose up fehlgeschlagen."

    log "Warte auf Healthchecks (app, web, mariadb, redis; bis 300 s)."
    deadline=$(( $(date +%s) + 300 ))
    while :; do
        unhealthy="$(compose ps --format '{{.Service}} {{.Health}}' 2>/dev/null | awk '$1=="app"||$1=="web"||$1=="mariadb"||$1=="redis"{ if ($2!="healthy") print $1 }' | tr '\n' ' ')"
        [[ -z "$unhealthy" ]] && break
        if [[ "$(date +%s)" -ge "$deadline" ]]; then
            compose ps || true
            compose logs --tail=50 app web mariadb || true
            fail "Container nicht healthy nach 300 s: $unhealthy"
        fi
        sleep 5
    done
    log "Basisdienste healthy. Migration (idempotent, mit Integritaetstriggern HUB_DB_TRIGGERS=true)."
    compose exec -T app php artisan migrate --force --no-interaction || fail "Migration fehlgeschlagen. Ausgabe oben pruefen (docker compose logs mariadb)."
    # Worker und Scheduler brauchen die Migration (Heartbeat-Jobs). Nach dem Start einmal alle Dienste anzeigen.
    compose restart worker mail-worker-high mail-worker scheduler >/dev/null 2>&1 || true
    log "hub:doctor:"
    compose exec -T app php artisan hub:doctor --no-interaction | sed 's/^/    /' || warn "hub:doctor meldet Punkte, Ausgabe oben pruefen."
    compose ps | sed 's/^/    /' || true

    # Health ueber den Proxy (nginx-Modus mit --skip-tls per HTTP mit Host-Header, sonst per TLS von aussen).
    # Traefik-Modus: Traefik entdeckt den Container ueber Labels und fordert das Zertifikat erst beim ersten Aufruf an;
    # das dauert bis zu einer Minute. TLS-Fehler (Standardzertifikat von Traefik) und 404/503 ohne Verbindung sind in
    # dieser Phase normal, deshalb bis zu 12 Versuche im Abstand von 10 s.
    if [[ "$SKIP_TLS" -eq 1 ]]; then
        code="$(curl -sS -o /dev/null -w '%{http_code}' -H "Host: $HUB_DOMAIN" "$HEALTH_URL" || true)"
    elif [[ "$PROXY_MODE" == "traefik" ]]; then
        log "Warte auf Routing und Zertifikat durch Traefik fuer $HUB_DOMAIN (bis 120 s)."
        code=""
        for attempt in $(seq 1 12); do
            code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" 2>/dev/null || true)"
            [[ "$code" == "200" || "$code" == "503" ]] && break
            [[ "$attempt" -lt 12 ]] && sleep 10
        done
    else
        code="$(curl -sS -o /dev/null -w '%{http_code}' "$HEALTH_URL" || true)"
    fi
    case "$code" in
        200) log "Health-Check $HEALTH_URL: 200" ;;
        503) warn "Health-Check $HEALTH_URL: 503. Erwartet, solange keine Immoware-Connection mit Sync-Stand existiert (Pruefpunkt immoware stale). Datenbank und Queue: /health/database, /health/queue." ;;
        *)
            if [[ "$PROXY_MODE" == "traefik" ]]; then
                warn "Health-Check $HEALTH_URL: HTTP ${code:-keine Antwort oder TLS-Fehler} nach 120 s. Pruefen: docker logs des Traefik-Containers (Zertifikat, Router immoware-hub), 'docker network inspect $TRAEFIK_NETWORK' (Container immoware-hub-web enthalten?), DNS A-Record, docker compose logs web."
                NEXT_STEPS+=("Health-Check wiederholen: curl -sSI https://$HUB_DOMAIN/health und curl -sSI https://$MAIL_DOMAIN/up")
            else
                warn "Health-Check $HEALTH_URL: HTTP ${code:-keine Antwort}. Host-nginx und docker compose logs web pruefen."
            fi
            ;;
    esac
fi

# ---------------------------------------------------------------------------
# Zusammenfassung
# ---------------------------------------------------------------------------
step "Zusammenfassung"
cat <<EOF
Server:            Ubuntu ${RELEASE_ID} (${RELEASE_CODENAME}), $CPU_CORES Kerne, ${MEM_TOTAL_GB} GB RAM, Zeitzone Europe/Berlin
Haertung:          ufw 22/80/443, fail2ban sshd, sshd-Drop-in 00-immoware-hardening.conf, unattended-upgrades (ohne Docker-Pakete)
Docker:            $DAEMON_JSON (json-file 50m x 5, live-restore), Deploy-Nutzer $DEPLOY_USER in Gruppe docker
Anwendung:         $APP_DIR ($BRANCH @ $GIT_SHA), compose.yaml + compose.override.yaml, Image immoware-hub:$GIT_SHA
Daten:             $DATA_DIR/{mariadb,redis,storage,imports} (Bind-Volumes), MariaDB Buffer Pool $MARIADB_BUFFER_POOL, Redis $REDIS_MAXMEMORY
php-fpm:           max_children=$PHP_FPM_MAX_CHILDREN ($ETC_DIR/php-fpm-pool.conf, Mount in app)
$( if [[ "$PROXY_MODE" == "traefik" ]]; then
cat <<EOT
Netz:              Container web ohne Host-Port, zusaetzlich im Netz $TRAEFIK_NETWORK (${TRAEFIK_SUBNET:-Subnetz unbekannt}); MariaDB und Redis ohne Port-Publish
TRUSTED_PROXIES:   $TRUSTED_PROXIES_VALUE (backend-Subnetz plus Traefik-Netz)
Traefik:           Router immoware-hub (Host $HUB_DOMAIN) und immoware-mail (Host $MAIL_DOMAIN), Entrypoint $TRAEFIK_ENTRYPOINT, Resolver $TRAEFIK_CERTRESOLVER, Service immoware-hub -> Port 8080, HSTS-Middleware
Host-nginx:        keiner (PROXY_MODE=traefik), kein certbot
EOT
else
cat <<EOT
Netz:              Container web nur 127.0.0.1:$WEB_PORT, MariaDB und Redis ohne Port-Publish, Subnetz $DOCKER_SUBNET = TRUSTED_PROXIES
Host-nginx:        $HUB_SITE, $MAIL_SITE $( [[ "$SKIP_TLS" -eq 1 ]] && echo "(HTTP-only)" || echo "(TLS, Let's Encrypt, certbot.timer, Renewal-Hook reload)" )
EOT
fi )
Backup:            /etc/cron.d/immoware-hub-backup taeglich 02:00 (root), deploy/scripts/backup-docker.sh, Umgebung $ETC_DIR/backup.env, Ziel $BACKUP_DIR
Credentials:       $CREDENTIALS_FILE (0600, nur root). Werte stehen zusaetzlich in $APP_DIR/.env (0600, $DEPLOY_USER).

Naechste Schritte:
  1. Ersten Admin-Nutzer anlegen (Rolle owner), danach 2FA bei der ersten Anmeldung einrichten:
       sudo -u $DEPLOY_USER -H docker compose --project-directory $APP_DIR exec app php artisan hub:user:create <email> --role=owner --name="<Name>" --organization-name="Hausverwaltung Müller GmbH"
  2. hub:doctor pruefen: sudo -u $DEPLOY_USER -H docker compose --project-directory $APP_DIR exec app php artisan hub:doctor
  3. Health: curl -fsS https://$HUB_DOMAIN/health/database && curl -fsS https://$HUB_DOMAIN/health/queue && curl -fsS https://$MAIL_DOMAIN/up
  4. Update spaeter: git pull, docker compose build app, up -d, exec app php artisan migrate --force (docs/operations/06-neuer-server.md, Abschnitt Docker)
EOF
for s in "${NEXT_STEPS[@]:-}"; do [[ -n "$s" ]] && echo "  * $s"; done
echo
echo "Erkennungen:"
for d in "${DETECTED[@]:-}"; do [[ -n "$d" ]] && echo "  - $d"; done
echo
echo "Vollstaendige Checkliste: docs/operations/06-neuer-server.md (Variante Docker auf Ubuntu 26.04)"
