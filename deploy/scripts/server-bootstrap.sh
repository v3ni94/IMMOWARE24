#!/usr/bin/env bash
# Immoware Hub, Ersteinrichtung eines frischen dedizierten Ubuntu-Servers (Variante B, Host-Installation, kein Docker).
#
# Richtet auf einem Server mit Ubuntu 24.04 LTS oder 26.04 LTS alles ein, was der Hub
# (immoware.muellerhv.de) und das Mail-Modul (mail.muellerhv.de) fuer den Betrieb brauchen:
# Systemhaertung (ufw, fail2ban, sshd, unattended-upgrades), PHP 8.4 (Distro-Paket, sonst ppa:ondrej/php, sonst
# packages.sury.org nur nach ausdruecklicher Freigabe), Composer 2, nginx, MariaDB 11.x, Redis (Paket redis-server),
# certbot, Deploy-Nutzer, Verzeichnisstruktur nach docs/operations/01-deployment.md Abschnitt 4.1, php-fpm Pool,
# nginx-Server-Bloecke aus deploy/nginx, systemd-Units aus deploy/systemd, shared/.env aus .env.example, logrotate,
# Backup-Cron und den ersten Deploy ueber deploy/scripts/deploy.sh.
#
# Aufruf als root:
#   bash deploy/scripts/server-bootstrap.sh [--dry-run] [--skip-tls] [--skip-deploy]
#     --dry-run     zeigt alle Schritte, veraendert nichts
#     --skip-tls    kein certbot, nginx bleibt HTTP-only (z. B. solange DNS noch nicht umgestellt ist)
#     --skip-deploy alles einrichten, aber deploy.sh nicht ausfuehren
#
# Alle Werte kommen aus den Variablen am Kopf oder aus der Umgebung (VARIABLE=wert bash ...). Das Skript enthaelt keine
# Secrets. Zufaellige Passwoerter landen ausschliesslich in /root/immoware-hub-credentials.txt (0600) und in
# /var/www/immoware-hub/shared/.env (0600, Eigentuemer immoware). Das Skript ist idempotent: ein zweiter Lauf ueberschreibt
# keine vorhandenen Secrets, keine vorhandene .env und keine vorhandenen Zertifikate.
#
# Externe Downloads ausserhalb von apt: nur der Composer-Installer von getcomposer.org, geprueft gegen die von
# composer.github.io veroeffentlichte SHA-384-Summe. Der MariaDB-Signaturschluessel wird per Fingerabdruck geprueft.
# Leitdokument: docs/operations/06-neuer-server.md.
#
# Release-Erkennung (Abschnitt 0): unterstuetzt sind 24.04 (noble) und 26.04 (resolute), alles andere bricht ab.
#   PHP_SOURCE=auto|distro|ondrej|sury   auto: Distro-Paket php8.4-*, sonst ppa:ondrej/php (nur wenn das Release dort
#                                        veroeffentlicht ist), sonst Abbruch. sury (packages.sury.org) nur ausdruecklich.
#   MARIADB_SOURCE=auto|mariadb|distro   auto: 24.04 mariadb.org 11.4, 26.04 Distro-Paket wenn >= 11.4 (11.8 erwartet).
#   BOOTSTRAP_FAKE_RELEASE=26.04         nur fuer --dry-run: simuliert ein anderes Release (VERSION_ID und Codename).

set -euo pipefail

# ---------------------------------------------------------------------------
# Variablen (per Umgebung ueberschreibbar)
# ---------------------------------------------------------------------------
HUB_DOMAIN="${HUB_DOMAIN:-immoware.muellerhv.de}"
MAIL_DOMAIN="${MAIL_DOMAIN:-mail.muellerhv.de}"
DEPLOY_USER="${DEPLOY_USER:-immoware}"
APP_DIR="${APP_DIR:-/var/www/immoware-hub}"
DB_NAME="${DB_NAME:-immoware_hub}"
DB_USER="${DB_USER:-immoware_hub}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"                        # Pflicht fuer certbot (Ablaufhinweise von Let's Encrypt)
REPO_URL="${REPO_URL:-git@github.com:muellerhv/IMMOWARE24.git}"
BRANCH="${BRANCH:-main}"
SSH_PUBKEY="${SSH_PUBKEY:-}"                          # optional: oeffentlicher Schluessel fuer den Admin-Zugang als root
DEPLOY_SSH_PUBKEY="${DEPLOY_SSH_PUBKEY:-}"            # optional: oeffentlicher Schluessel, mit dem GitHub Actions als DEPLOY_USER deployt
SERVER_IPV4="${SERVER_IPV4:-}"                        # optional: oeffentliche IPv4, sonst automatisch ermittelt
PHP_VERSION="${PHP_VERSION:-8.4}"
PHP_SOURCE="${PHP_SOURCE:-auto}"                      # auto | distro | ondrej | sury (sury nur nach Ruecksprache, ungetestet)
MARIADB_SOURCE="${MARIADB_SOURCE:-auto}"              # auto | mariadb (deb.mariadb.org 11.x LTS) | distro (Ubuntu-Paket)
MARIADB_VERSION="${MARIADB_VERSION:-11.4}"            # nur bei MARIADB_SOURCE=mariadb; 11.4 ist LTS und entspricht compose.yaml und CI
MARIADB_MIN_DISTRO_VERSION="${MARIADB_MIN_DISTRO_VERSION:-11.4}"   # Distro-Paket nur, wenn mindestens diese Version
BOOTSTRAP_FAKE_RELEASE="${BOOTSTRAP_FAKE_RELEASE:-}"  # nur --dry-run: 24.04 oder 26.04 simulieren
MARIADB_BUFFER_POOL="${MARIADB_BUFFER_POOL:-512M}"
REDIS_MAXMEMORY="${REDIS_MAXMEMORY:-256mb}"
CREDENTIALS_FILE="${CREDENTIALS_FILE:-/root/immoware-hub-credentials.txt}"
LOG_DIR="/var/log/immoware-hub"
ETC_DIR="/etc/immoware-hub"
BACKUP_DIR="/var/backups/immoware-hub"
LETSENCRYPT_WEBROOT="/var/www/letsencrypt"
FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm-${DEPLOY_USER}.sock"
SRC_DIR="${SRC_DIR:-$APP_DIR/bootstrap-src}"          # Checkout des Repositories fuer Konfigurationsvorlagen

DRY_RUN=0
SKIP_TLS=0
SKIP_DEPLOY=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --skip-tls) SKIP_TLS=1 ;;
        --skip-deploy) SKIP_DEPLOY=1 ;;
        -h|--help) sed -n '2,32p' "$0"; exit 0 ;;
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

# run: fuehrt einen Befehl aus oder zeigt ihn im Dry-Run nur an.
run() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '    [dry-run] %q' "$1"; shift; for a in "$@"; do printf ' %q' "$a"; done; printf '\n'
        return 0
    fi
    "$@"
}

# write_file <pfad> <modus> <eigentuemer> : schreibt stdin nach <pfad>, nur wenn sich der Inhalt aendert.
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

# credential_get <KEY>: liest einen Wert aus der Credentials-Datei (leer, wenn nicht vorhanden).
credential_get() { [[ -f "$CREDENTIALS_FILE" ]] && sed -n "s/^$1=//p" "$CREDENTIALS_FILE" | head -n1 || true; }

# credential_set <KEY> <WERT>: schreibt oder ersetzt einen Wert in der Credentials-Datei (0600).
credential_set() {
    [[ "$DRY_RUN" -eq 1 ]] && { printf '    [dry-run] merke %s in %s\n' "$1" "$CREDENTIALS_FILE"; return 0; }
    touch "$CREDENTIALS_FILE"; chmod 600 "$CREDENTIALS_FILE"
    if grep -q "^$1=" "$CREDENTIALS_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$CREDENTIALS_FILE"
    else
        printf '%s=%s\n' "$1" "$2" >> "$CREDENTIALS_FILE"
    fi
}

CHANGED_FILES=()
NEXT_STEPS=()
DETECTED=()          # Erkennungen fuer die Zusammenfassung
note() { DETECTED+=("$*"); log "$*"; }

# version_ge <a> <b>: 1 wenn a >= b (numerischer Vergleich von Punktversionen)
version_ge() { [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" == "$2" ]]; }

# apt_candidate <paket>: Kandidatenversion aus apt-cache policy, leer wenn kein Kandidat.
apt_candidate() {
    local c
    # Simuliertes Release: Kandidaten des echten Systems wuerden das Bild verfaelschen, daher "kein Kandidat".
    [[ -n "$BOOTSTRAP_FAKE_RELEASE" ]] && return 0
    c="$(apt-cache policy "$1" 2>/dev/null | awk '/Candidate:/{print $2; exit}')"
    [[ -n "$c" && "$c" != "(none)" && "$c" != "(keine)" ]] && printf '%s' "$c" || true
}

# apt_candidate_origin <paket>: Quelle (Hostname oder Site) der Kandidatenversion, z. B. ppa.launchpadcontent.net
apt_candidate_origin() {
    apt-cache policy "$1" 2>/dev/null | awk '/^ \*\*\*/{getline; print $2; exit}'
}

# pkg_installed <paket>
pkg_installed() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q "install ok installed"; }

# ---------------------------------------------------------------------------
# 0. Vorpruefungen
# ---------------------------------------------------------------------------
step "Vorpruefungen"
[[ "$(id -u)" -eq 0 ]] || fail "Bitte als root ausfuehren."
[[ -r /etc/os-release ]] || fail "/etc/os-release fehlt, kein Ubuntu?"
# shellcheck source=/dev/null
. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || fail "Unterstuetzt wird nur Ubuntu (gefunden: ${ID:-unbekannt})."
RELEASE_ID="${VERSION_ID:-}"
RELEASE_CODENAME="${UBUNTU_CODENAME:-${VERSION_CODENAME:-}}"
if command -v lsb_release >/dev/null 2>&1; then
    lsb_id="$(lsb_release -rs 2>/dev/null || true)"; lsb_cn="$(lsb_release -cs 2>/dev/null || true)"
    [[ -n "$lsb_id" ]] && RELEASE_ID="$lsb_id"
    [[ -n "$lsb_cn" ]] && RELEASE_CODENAME="$lsb_cn"
fi
if [[ -n "$BOOTSTRAP_FAKE_RELEASE" ]]; then
    [[ "$DRY_RUN" -eq 1 ]] || fail "BOOTSTRAP_FAKE_RELEASE ist nur zusammen mit --dry-run erlaubt."
    RELEASE_ID="$BOOTSTRAP_FAKE_RELEASE"
    case "$RELEASE_ID" in 24.04) RELEASE_CODENAME=noble ;; 26.04) RELEASE_CODENAME=resolute ;; *) RELEASE_CODENAME=unbekannt ;; esac
    warn "Simuliertes Release: Ubuntu $RELEASE_ID ($RELEASE_CODENAME). Paketkandidaten werden nicht vom echten System uebernommen, es gelten die dokumentierten Annahmen."
fi
case "$RELEASE_ID" in
    24.04) [[ -n "$RELEASE_CODENAME" ]] || RELEASE_CODENAME=noble
           note "Release: Ubuntu 24.04 LTS ($RELEASE_CODENAME), getesteter Zielstand." ;;
    26.04) [[ -n "$RELEASE_CODENAME" ]] || RELEASE_CODENAME=resolute
           note "Release: Ubuntu 26.04 LTS ($RELEASE_CODENAME). Unterstuetzt, aber ohne echten Referenzlauf (docs/operations/06-neuer-server.md Abschnitt 9)." ;;
    *) fail "Ubuntu ${RELEASE_ID:-unbekannt} wird nicht unterstuetzt. Unterstuetzt sind 24.04 LTS (noble) und 26.04 LTS (resolute)." ;;
esac
case "$PHP_SOURCE" in auto|distro|ondrej|sury) ;; *) fail "PHP_SOURCE ungueltig: $PHP_SOURCE (auto, distro, ondrej, sury)." ;; esac
case "$MARIADB_SOURCE" in auto|mariadb|distro) ;; *) fail "MARIADB_SOURCE ungueltig: $MARIADB_SOURCE (auto, mariadb, distro)." ;; esac
[[ "$PHP_VERSION" == "8.4" ]] || warn "PHP_VERSION=$PHP_VERSION abweichend vom getesteten Stand 8.4 (composer.json, CI). Nur nach Ruecksprache."
[[ "$SKIP_TLS" -eq 1 || -n "$ADMIN_EMAIL" ]] || fail "ADMIN_EMAIL ist fuer certbot Pflicht (oder --skip-tls verwenden)."
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || fail "DEPLOY_USER ungueltig: $DEPLOY_USER"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB_NAME und DB_USER duerfen nur Buchstaben, Ziffern und Unterstrich enthalten."
[[ "$DRY_RUN" -eq 1 ]] && warn "Dry-Run: es wird nichts veraendert."
log "Hub: $HUB_DOMAIN, Mail: $MAIL_DOMAIN, Nutzer: $DEPLOY_USER, App: $APP_DIR, Repo: $REPO_URL ($BRANCH)"

# ---------------------------------------------------------------------------
# 1. Basissystem: Pakete, Updates, Zeitzone
# ---------------------------------------------------------------------------
step "1. Basissystem, apt update/upgrade, Zeitzone Europe/Berlin"
run apt-get update -q
run apt-get -o Dpkg::Options::=--force-confold upgrade -y -q
run apt-get install -y -q ca-certificates curl gnupg lsb-release software-properties-common apt-transport-https \
    ufw fail2ban unattended-upgrades apt-listchanges git unzip zstd gnupg2 dnsutils acl openssl logrotate cron \
    rsync jq
run timedatectl set-timezone Europe/Berlin

write_file /etc/apt/apt.conf.d/20auto-upgrades 0644 root:root <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF
write_file /etc/apt/apt.conf.d/52immoware-unattended 0644 root:root <<'EOF'
// Immoware Hub: Sicherheitsupdates automatisch, kein automatischer Neustart (Neustart geplant im Wartungsfenster).
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
EOF
run systemctl enable --now unattended-upgrades

# ---------------------------------------------------------------------------
# 2. Firewall, fail2ban, sshd
# ---------------------------------------------------------------------------
step "2. ufw (22, 80, 443), fail2ban sshd, sshd-Haertung"
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
        warn "SSH_PUBKEY ist kein gueltiger oeffentlicher Schluessel. Passwort-Anmeldung bleibt aktiv."
    fi
fi

# Drop-in mit niedriger Nummer, damit er vor 50-cloud-init.conf gelesen wird (bei sshd gewinnt der erste Wert).
{
    echo "# Immoware Hub, sshd-Haertung (deploy/scripts/server-bootstrap.sh)"
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
[[ "$ROOT_KEY_OK" -eq 1 ]] || warn "Passwort-Anmeldung per SSH bleibt aktiv, weil kein SSH_PUBKEY hinterlegt wurde. Nach Hinterlegen eines Schluessels Skript erneut ausfuehren."
if [[ "$DRY_RUN" -eq 0 ]]; then
    sshd -t || fail "sshd-Konfiguration ungueltig, keine Aenderung aktiviert."
    if file_changed /etc/ssh/sshd_config.d/00-immoware-hardening.conf; then
        systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
    fi
fi

# ---------------------------------------------------------------------------
# 3. Deploy-Nutzer
# ---------------------------------------------------------------------------
step "3. Deploy-Nutzer $DEPLOY_USER (Passwort gesperrt, nur SSH-Schluessel)"
if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    run useradd --system --create-home --home-dir "/home/$DEPLOY_USER" --shell /bin/bash --user-group "$DEPLOY_USER"
fi
run passwd -l "$DEPLOY_USER"
if [[ "$DRY_RUN" -eq 0 ]]; then
    install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh"
    touch "/home/$DEPLOY_USER/.ssh/authorized_keys"
    chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"; chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
    if [[ -n "$DEPLOY_SSH_PUBKEY" ]]; then
        if printf '%s\n' "$DEPLOY_SSH_PUBKEY" | ssh-keygen -l -f /dev/stdin >/dev/null 2>&1; then
            grep -qxF "$DEPLOY_SSH_PUBKEY" "/home/$DEPLOY_USER/.ssh/authorized_keys" || printf '%s\n' "$DEPLOY_SSH_PUBKEY" >> "/home/$DEPLOY_USER/.ssh/authorized_keys"
            log "Deploy-Schluessel (GitHub Actions) fuer $DEPLOY_USER hinterlegt."
        else
            warn "DEPLOY_SSH_PUBKEY ist kein gueltiger oeffentlicher Schluessel, uebersprungen."
        fi
    fi
    # Eigener Schluessel des Deploy-Nutzers fuer git clone vom Repository (als Read-only Deploy Key in GitHub hinterlegen).
    if [[ ! -f "/home/$DEPLOY_USER/.ssh/id_ed25519" ]]; then
        sudo -u "$DEPLOY_USER" -H ssh-keygen -q -t ed25519 -N '' -C "$DEPLOY_USER@$HUB_DOMAIN deploy" -f "/home/$DEPLOY_USER/.ssh/id_ed25519"
        log "Neuer SSH-Schluessel fuer $DEPLOY_USER erzeugt (git clone)."
    fi
    if [[ "$REPO_URL" == git@github.com:* || "$REPO_URL" == ssh://git@github.com/* ]]; then
        if ! sudo -u "$DEPLOY_USER" -H ssh-keygen -F github.com -f "/home/$DEPLOY_USER/.ssh/known_hosts" >/dev/null 2>&1; then
            ssh-keyscan -t ed25519,rsa,ecdsa github.com 2>/dev/null | sudo -u "$DEPLOY_USER" -H tee -a "/home/$DEPLOY_USER/.ssh/known_hosts" >/dev/null || true
            warn "GitHub-Host-Schluessel per ssh-keyscan uebernommen. Fingerabdruck gegen https://docs.github.com/de/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints pruefen."
        fi
    fi
fi

# sudo nur fuer das, was deploy.sh braucht: php-fpm reload, Worker-Units stoppen und starten, Units auflisten.
write_file /etc/sudoers.d/immoware-hub 0440 root:root <<EOF
# Immoware Hub, deploy.sh benoetigt genau diese Befehle (docs/operations/01-deployment.md Abschnitt 2)
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_VERSION}-fpm
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl list-units *
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl stop immoware-hub-*
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl start immoware-hub-*
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl restart immoware-hub-*
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl status immoware-hub-*
EOF
[[ "$DRY_RUN" -eq 1 ]] || visudo -cf /etc/sudoers.d/immoware-hub >/dev/null || fail "sudoers-Datei ungueltig."

# ---------------------------------------------------------------------------
# 4. Verzeichnisse (01-deployment.md Abschnitt 4.1)
# ---------------------------------------------------------------------------
step "4. Verzeichnisse unter $APP_DIR, $ETC_DIR, $LOG_DIR, $BACKUP_DIR"
run install -d -m 755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/shared"
run install -d -m 775 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_DIR/shared/storage" \
    "$APP_DIR/shared/storage/app" "$APP_DIR/shared/storage/app/imports" "$APP_DIR/shared/storage/app/public" \
    "$APP_DIR/shared/storage/framework" "$APP_DIR/shared/storage/framework/cache" "$APP_DIR/shared/storage/framework/cache/data" \
    "$APP_DIR/shared/storage/framework/sessions" "$APP_DIR/shared/storage/framework/views" "$APP_DIR/shared/storage/logs"
run install -d -m 750 -o root -g root "$ETC_DIR"
run install -d -m 750 -o "$DEPLOY_USER" -g adm "$LOG_DIR"
run install -d -m 750 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$BACKUP_DIR"
run install -d -m 755 -o www-data -g www-data "$LETSENCRYPT_WEBROOT"

# ---------------------------------------------------------------------------
# 5. Repository-Checkout fuer Konfigurationsvorlagen
# ---------------------------------------------------------------------------
step "5. Repository fuer Vorlagen ($REPO_URL, $BRANCH)"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ "$DRY_RUN" -eq 0 ]]; then
    # deploy.sh klont spaeter als $DEPLOY_USER aus REPO_URL. Erreichbarkeit vorab pruefen, sonst klare Anleitung.
    if ! sudo -u "$DEPLOY_USER" -H git ls-remote --exit-code --heads "$REPO_URL" "$BRANCH" >/dev/null 2>&1; then
        echo
        echo "Das Repository $REPO_URL ($BRANCH) ist fuer $DEPLOY_USER nicht erreichbar."
        echo "Bei einem privaten GitHub-Repository diesen oeffentlichen Schluessel als Deploy Key (nur Lesen) hinterlegen"
        echo "(Repository, Settings, Deploy keys) und das Skript danach erneut ausfuehren:"
        echo
        cat "/home/$DEPLOY_USER/.ssh/id_ed25519.pub"
        echo
        fail "Repository nicht erreichbar."
    fi
fi
if [[ -f "$SCRIPT_DIR/../../.env.example" && -d "$SCRIPT_DIR/../nginx" ]]; then
    SRC_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
    log "Vorlagen aus lokalem Checkout: $SRC_DIR"
elif [[ "$DRY_RUN" -eq 1 ]]; then
    log "[dry-run] wuerde $REPO_URL nach $SRC_DIR klonen."
else
    if [[ -d "$SRC_DIR/.git" ]]; then
        sudo -u "$DEPLOY_USER" -H git -C "$SRC_DIR" fetch --quiet --depth 1 origin "$BRANCH"
        sudo -u "$DEPLOY_USER" -H git -C "$SRC_DIR" checkout --quiet -B "$BRANCH" FETCH_HEAD
    else
        sudo -u "$DEPLOY_USER" -H git clone --quiet --depth 1 --branch "$BRANCH" "$REPO_URL" "$SRC_DIR"
    fi
fi
if [[ "$DRY_RUN" -eq 0 ]]; then
    for f in .env.example deploy/nginx/"$HUB_DOMAIN".conf deploy/nginx/"$MAIL_DOMAIN".conf deploy/systemd/immoware-hub-worker@.service \
             deploy/systemd/immoware-hub-mail-worker@.service deploy/systemd/immoware-hub-scheduler.service deploy/scripts/deploy.sh; do
        [[ -f "$SRC_DIR/$f" ]] || fail "Vorlage fehlt im Repository: $f (Domains muessen zu den Dateinamen in deploy/nginx passen)."
    done
fi

# ---------------------------------------------------------------------------
# 6. PHP 8.4 (ondrej/php) und Composer 2
# ---------------------------------------------------------------------------
step "6. PHP $PHP_VERSION (Quelle: $PHP_SOURCE), Composer 2"
# Reihenfolge bei auto: Distro-Paket, sonst ppa:ondrej/php (nur wenn Launchpad das Release veroeffentlicht), sonst Abbruch.
# packages.sury.org (PHP_SOURCE=sury) wird nie automatisch gewaehlt: fuer 26.04 ist es laut Recherche der von Ondrej Sury
# empfohlene Weg, im Projekt aber ungetestet (Ruecksprache erforderlich, docs/operations/06-neuer-server.md Abschnitt 9).
PHP_PKG="php${PHP_VERSION}-fpm"
PHP_ONDREJ_LIST="/etc/apt/sources.list.d/ondrej-ubuntu-php-${RELEASE_CODENAME}"
PHP_SURY_LIST="/etc/apt/sources.list.d/sury-php.sources"
PHP_SURY_KEYRING="/usr/share/keyrings/deb.sury.org-php.gpg"
PHP_RESOLVED=""

php_try_distro() {
    local cand origin
    cand="$(apt_candidate "$PHP_PKG")"
    [[ -n "$cand" ]] || return 1
    origin="$(apt_candidate_origin "$PHP_PKG")"
    # Nur als Distro werten, wenn der Kandidat nicht aus einem Fremd-Repository kommt (Quelle oder Versionskennung).
    case "$origin" in *launchpadcontent*|*ppa.launchpad*|*sury.org*) return 1 ;; esac
    case "$cand" in *sury.org*|*ppa*) return 1 ;; esac
    PHP_RESOLVED="distro"; note "PHP: $PHP_PKG $cand aus Ubuntu-Paketquelle (${origin:-lokal})."
}

php_try_ondrej() {
    local cand
    if [[ -f "$PHP_ONDREJ_LIST.sources" || -f "$PHP_ONDREJ_LIST.list" ]]; then
        cand="$(apt_candidate "$PHP_PKG")"
        [[ -n "$cand" ]] && { PHP_RESOLVED="ondrej"; note "PHP: $PHP_PKG $cand aus ppa:ondrej/php (bereits eingerichtet)."; return 0; }
        return 1
    fi
    if [[ "$DRY_RUN" -eq 1 ]]; then
        log "[dry-run] wuerde add-apt-repository -y ppa:ondrej/php versuchen und per apt-cache policy pruefen, ob $PHP_PKG fuer $RELEASE_CODENAME veroeffentlicht ist."
        case "$RELEASE_CODENAME" in
            noble|jammy) PHP_RESOLVED="ondrej"; note "PHP: ppa:ondrej/php veroeffentlicht fuer $RELEASE_CODENAME (Annahme im Dry-Run, Stand Recherche 20.09.2026)."; return 0 ;;
            *) note "PHP: ppa:ondrej/php veroeffentlicht laut Recherche (20.09.2026) nicht fuer $RELEASE_CODENAME (nur noble, jammy)."; return 1 ;;
        esac
    fi
    if ! add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || ! apt-get update -q 2>/dev/null; then
        warn "ppa:ondrej/php fuer $RELEASE_CODENAME nicht einrichtbar, PPA wird wieder entfernt."
        add-apt-repository -r -y ppa:ondrej/php >/dev/null 2>&1 || rm -f "$PHP_ONDREJ_LIST.sources" "$PHP_ONDREJ_LIST.list"
        apt-get update -q >/dev/null 2>&1 || true
        return 1
    fi
    cand="$(apt_candidate "$PHP_PKG")"
    if [[ -z "$cand" ]]; then
        warn "ppa:ondrej/php liefert kein $PHP_PKG fuer $RELEASE_CODENAME, PPA wird wieder entfernt."
        add-apt-repository -r -y ppa:ondrej/php >/dev/null 2>&1 || rm -f "$PHP_ONDREJ_LIST.sources" "$PHP_ONDREJ_LIST.list"
        apt-get update -q >/dev/null 2>&1 || true
        return 1
    fi
    PHP_RESOLVED="ondrej"; note "PHP: $PHP_PKG $cand aus ppa:ondrej/php."
}

php_try_sury() {
    # Ausdruecklich angefordert (PHP_SOURCE=sury). Schluessel kommt aus dem von packages.sury.org veroeffentlichten
    # Keyring-Paket; der Fingerabdruck konnte in der Entwicklungsumgebung nicht verifiziert werden (Egress gesperrt).
    warn "PHP_SOURCE=sury: packages.sury.org wird eingerichtet. Im Projekt ungetestet, Signaturschluessel wird nicht gegen einen festen Fingerabdruck geprueft."
    if [[ "$DRY_RUN" -eq 1 ]]; then
        log "[dry-run] wuerde https://packages.sury.org/debsuryorg-archive-keyring.deb installieren und $PHP_SURY_LIST (Suite $RELEASE_CODENAME) anlegen."
        PHP_RESOLVED="sury"; note "PHP: $PHP_PKG aus packages.sury.org (Annahme im Dry-Run)."; return 0
    fi
    if [[ ! -f "$PHP_SURY_KEYRING" ]]; then
        tmpd="$(mktemp -d)"
        curl -fsSL https://packages.sury.org/debsuryorg-archive-keyring.deb -o "$tmpd/keyring.deb" || { rm -rf "$tmpd"; fail "packages.sury.org Keyring nicht abrufbar."; }
        dpkg -i "$tmpd/keyring.deb" >/dev/null || { rm -rf "$tmpd"; fail "packages.sury.org Keyring nicht installierbar."; }
        rm -rf "$tmpd"
    fi
    write_file "$PHP_SURY_LIST" 0644 root:root <<EOF
# PHP von packages.sury.org (Ondrej Sury), eingerichtet durch deploy/scripts/server-bootstrap.sh (PHP_SOURCE=sury)
Types: deb
URIs: https://packages.sury.org/php/
Suites: $RELEASE_CODENAME
Components: main
Signed-By: $PHP_SURY_KEYRING
EOF
    apt-get update -q
    cand="$(apt_candidate "$PHP_PKG")"
    [[ -n "$cand" ]] || fail "packages.sury.org liefert kein $PHP_PKG fuer $RELEASE_CODENAME."
    PHP_RESOLVED="sury"; note "PHP: $PHP_PKG $cand aus packages.sury.org."
}

case "$PHP_SOURCE" in
    distro) php_try_distro || fail "PHP_SOURCE=distro: $PHP_PKG ist in den Ubuntu-Paketquellen von $RELEASE_CODENAME nicht vorhanden." ;;
    ondrej) php_try_ondrej || fail "PHP_SOURCE=ondrej: ppa:ondrej/php liefert kein $PHP_PKG fuer $RELEASE_CODENAME." ;;
    sury)   php_try_sury ;;
    auto)
        php_try_distro || php_try_ondrej || {
            distro_php="$(apt_candidate php-fpm)"
            if [[ "$DRY_RUN" -eq 1 && -n "$BOOTSTRAP_FAKE_RELEASE" ]]; then
                PHP_RESOLVED="keine"
                note "PHP: kein $PHP_PKG fuer $RELEASE_CODENAME auffindbar. Auf dem Zielsystem bricht das Skript hier ab (kein PHP 8.5 ohne Ruecksprache; Option nach Freigabe: PHP_SOURCE=sury)."
            else
            fail "Kein PHP $PHP_VERSION fuer Ubuntu $RELEASE_ID ($RELEASE_CODENAME) gefunden: weder Distro-Paket $PHP_PKG noch ppa:ondrej/php. \
Die Distro liefert php-fpm ${distro_php:-unbekannt} (26.04: PHP 8.5, im Projekt nicht getestet, kein Wechsel ohne Ruecksprache). \
Optionen nach Ruecksprache: PHP_SOURCE=sury (packages.sury.org, ungetestet) oder Ubuntu 24.04 LTS verwenden."
            fi
        } ;;
esac

run apt-get install -y -q "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-redis" \
    "php${PHP_VERSION}-intl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-gd" "php${PHP_VERSION}-opcache"
run update-alternatives --set php "/usr/bin/php${PHP_VERSION}"

if [[ ! -x /usr/local/bin/composer ]]; then
    if [[ "$DRY_RUN" -eq 1 ]]; then
        log "[dry-run] wuerde Composer 2 mit SHA-384-Pruefung nach /usr/local/bin/composer installieren."
    else
        tmpd="$(mktemp -d)"
        expected="$(curl -fsSL https://composer.github.io/installer.sig)"
        curl -fsSL https://getcomposer.org/installer -o "$tmpd/composer-setup.php"
        actual="$(php -r "echo hash_file('sha384', '$tmpd/composer-setup.php');")"
        [[ "$expected" == "$actual" ]] || { rm -rf "$tmpd"; fail "Composer-Installer: SHA-384 stimmt nicht ueberein. Abbruch."; }
        php "$tmpd/composer-setup.php" --quiet --2 --install-dir=/usr/local/bin --filename=composer
        rm -rf "$tmpd"
        log "Composer installiert: $(/usr/local/bin/composer --version 2>/dev/null | head -n1)"
    fi
fi

# Laufzeitwerte aus docker/php/php.ini, fuer fpm und cli
for sapi in fpm cli; do
    write_file "/etc/php/${PHP_VERSION}/${sapi}/conf.d/98-immoware.ini" 0644 root:root <<'EOF'
; Immoware Hub, PHP-Laufzeit (abgeleitet aus docker/php/php.ini)
expose_php = Off
display_errors = Off
log_errors = On
memory_limit = 512M
max_execution_time = 60
upload_max_filesize = 32M
post_max_size = 34M
date.timezone = UTC
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = Lax
realpath_cache_size = 4096K
realpath_cache_ttl = 600
EOF
done
# OPcache aus docker/php/opcache.ini. validate_timestamps=0: deploy.sh laedt php-fpm nach jedem Release neu.
write_file "/etc/php/${PHP_VERSION}/fpm/conf.d/99-immoware-opcache.ini" 0644 root:root <<'EOF'
; Immoware Hub, OPcache Produktion (docker/php/opcache.ini)
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
opcache.save_comments = 1
opcache.jit = tracing
opcache.jit_buffer_size = 64M
EOF

# php-fpm Pool (docker/php/www.conf, angepasst auf Nutzer und Unix-Socket, 01-deployment.md Abschnitt 4.2)
write_file "/etc/php/${PHP_VERSION}/fpm/pool.d/${DEPLOY_USER}.conf" 0644 root:root <<EOF
; Immoware Hub, php-fpm Pool (abgeleitet aus docker/php/www.conf)
[$DEPLOY_USER]
user = $DEPLOY_USER
group = $DEPLOY_USER
listen = $FPM_SOCKET
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
pm.status_path = /fpm-status
ping.path = /fpm-ping
request_terminate_timeout = 120s
clear_env = no
catch_workers_output = yes
decorate_workers_output = no
access.log = $LOG_DIR/php-fpm-access.log
php_admin_value[error_log] = $LOG_DIR/php-fpm-error.log
php_admin_flag[log_errors] = on
EOF
if [[ -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" ]]; then
    run mv "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.disabled"
fi
run systemctl enable "php${PHP_VERSION}-fpm"
if [[ "$DRY_RUN" -eq 0 ]]; then
    "php-fpm${PHP_VERSION}" -t >/dev/null 2>&1 || fail "php-fpm Konfiguration ungueltig (php-fpm${PHP_VERSION} -t)."
    systemctl restart "php${PHP_VERSION}-fpm"
fi

# ---------------------------------------------------------------------------
# 7. MariaDB 11.x LTS
# ---------------------------------------------------------------------------
step "7. MariaDB (MARIADB_SOURCE=$MARIADB_SOURCE)"
# Distro-Kandidat ermitteln (Upstream-Version ohne Epoch und Debian-Revision, z. B. 1:11.8.6-5 -> 11.8.6).
MARIADB_DISTRO_CANDIDATE="$(apt_candidate mariadb-server)"
MARIADB_DISTRO_UPSTREAM="${MARIADB_DISTRO_CANDIDATE#*:}"; MARIADB_DISTRO_UPSTREAM="${MARIADB_DISTRO_UPSTREAM%%-*}"
if [[ "$MARIADB_SOURCE" == "auto" ]]; then
    case "$RELEASE_ID" in
        24.04)
            # Recherche 20.09.2026: noble liefert 10.11, deb.mariadb.org veroeffentlicht fuer noble. Zielstand 11.4 LTS.
            MARIADB_SOURCE="mariadb" ;;
        26.04)
            # Recherche 20.09.2026: resolute liefert 11.8 LTS, deb.mariadb.org veroeffentlicht (noch) nicht fuer resolute.
            if [[ -n "$MARIADB_DISTRO_UPSTREAM" ]] && version_ge "$MARIADB_DISTRO_UPSTREAM" "$MARIADB_MIN_DISTRO_VERSION"; then
                MARIADB_SOURCE="distro"
            elif [[ "$DRY_RUN" -eq 1 && -z "$MARIADB_DISTRO_CANDIDATE" ]]; then
                MARIADB_SOURCE="distro"
                warn "[dry-run] apt kennt hier kein mariadb-server (simuliertes Release). Annahme fuer 26.04: Distro-Paket 11.8 (>= $MARIADB_MIN_DISTRO_VERSION)."
            else
                warn "Distro-MariaDB ${MARIADB_DISTRO_UPSTREAM:-unbekannt} liegt unter $MARIADB_MIN_DISTRO_VERSION, deb.mariadb.org wird versucht (fuer $RELEASE_CODENAME laut Recherche nicht veroeffentlicht)."
                MARIADB_SOURCE="mariadb"
            fi ;;
    esac
fi
note "MariaDB: Quelle $MARIADB_SOURCE, Distro-Kandidat ${MARIADB_DISTRO_CANDIDATE:-keiner}$( [[ "$MARIADB_SOURCE" == mariadb ]] && echo ", deb.mariadb.org $MARIADB_VERSION Suite $RELEASE_CODENAME" )."
if [[ "$MARIADB_SOURCE" == "mariadb" ]]; then
    MARIADB_KEYRING=/etc/apt/keyrings/mariadb-keyring.pgp
    MARIADB_FINGERPRINT="177F4010FE56CA3336300305F1656F24C74CD1D8"   # MariaDB Signing Key (mariadb.org), Fingerabdruck fest hinterlegt
    if [[ ! -f "$MARIADB_KEYRING" ]]; then
        if [[ "$DRY_RUN" -eq 1 ]]; then
            log "[dry-run] wuerde den MariaDB-Signaturschluessel $MARIADB_FINGERPRINT vom Keyserver holen und pruefen."
        else
            install -d -m 755 /etc/apt/keyrings
            tmpk="$(mktemp -d)"
            GNUPGHOME="$tmpk" gpg --batch --quiet --keyserver hkps://keyserver.ubuntu.com --recv-keys "0x$MARIADB_FINGERPRINT" \
                || { rm -rf "$tmpk"; fail "MariaDB-Signaturschluessel nicht abrufbar. Alternative: MARIADB_SOURCE=distro (Ubuntu-Paket)."; }
            got="$(GNUPGHOME="$tmpk" gpg --batch --with-colons --fingerprint "0x$MARIADB_FINGERPRINT" | awk -F: '$1=="fpr"{print $10; exit}')"
            [[ "$got" == "$MARIADB_FINGERPRINT" ]] || { rm -rf "$tmpk"; fail "Fingerabdruck des MariaDB-Schluessels stimmt nicht ($got)."; }
            GNUPGHOME="$tmpk" gpg --batch --export "0x$MARIADB_FINGERPRINT" > "$MARIADB_KEYRING"
            chmod 644 "$MARIADB_KEYRING"; rm -rf "$tmpk"
        fi
    fi
    write_file /etc/apt/sources.list.d/mariadb.sources 0644 root:root <<EOF
# MariaDB $MARIADB_VERSION LTS (deb.mariadb.org), eingerichtet durch deploy/scripts/server-bootstrap.sh
X-Repolib-Name: MariaDB
Types: deb
URIs: https://deb.mariadb.org/$MARIADB_VERSION/ubuntu
Suites: $RELEASE_CODENAME
Components: main main/debug
Signed-By: $MARIADB_KEYRING
EOF
    if [[ "$DRY_RUN" -eq 0 ]] && file_changed /etc/apt/sources.list.d/mariadb.sources; then
        if ! apt-get update -q 2>/dev/null; then
            rm -f /etc/apt/sources.list.d/mariadb.sources; apt-get update -q >/dev/null 2>&1 || true
            fail "deb.mariadb.org bietet fuer $RELEASE_CODENAME keine Suite $MARIADB_VERSION an (Repository wieder entfernt). Alternative: MARIADB_SOURCE=distro, wenn die Distro-Version ${MARIADB_DISTRO_UPSTREAM:-unbekannt} ausreicht (>= $MARIADB_MIN_DISTRO_VERSION)."
        fi
        cand_origin="$(apt_candidate_origin mariadb-server)"
        [[ "$cand_origin" == *mariadb.org* ]] || warn "mariadb-server-Kandidat kommt nicht von deb.mariadb.org (${cand_origin:-unbekannt}), Repository greift nicht."
    fi
else
    if [[ -n "$MARIADB_DISTRO_UPSTREAM" ]] && ! version_ge "$MARIADB_DISTRO_UPSTREAM" "$MARIADB_MIN_DISTRO_VERSION"; then
        warn "MARIADB_SOURCE=distro: Ubuntu-Paket $MARIADB_DISTRO_UPSTREAM liegt unter dem Zielstand $MARIADB_MIN_DISTRO_VERSION (compose.yaml, CI: 11.4)."
    fi
fi
run apt-get install -y -q mariadb-server mariadb-client mariadb-backup
if [[ "$DRY_RUN" -eq 0 ]]; then
    MARIADB_INSTALLED="$(mariadb --version 2>/dev/null | sed 's/.*Distrib \([0-9.]*\).*/\1/')"
    note "MariaDB installiert: ${MARIADB_INSTALLED:-unbekannt} (Quelle $MARIADB_SOURCE)."
fi

write_file /etc/mysql/mariadb.conf.d/60-immoware.cnf 0644 root:root <<EOF
# Immoware Hub, MariaDB (Werte analog compose.yaml). Nur lokale Verbindungen.
[mysqld]
bind-address = 127.0.0.1
skip-name-resolve = 1
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
innodb_buffer_pool_size = $MARIADB_BUFFER_POOL
max_connections = 200
log_bin = mariadb-bin
binlog_format = ROW
binlog_expire_logs_seconds = 604800
# Integritaets-Trigger (docs/architecture/03-mariadb-triggers.sql) werden vom Anwendungsnutzer ohne SUPER angelegt.
log_bin_trust_function_creators = 1
[client]
default-character-set = utf8mb4
EOF
run systemctl enable mariadb
if [[ "$DRY_RUN" -eq 0 ]]; then
    file_changed /etc/mysql/mariadb.conf.d/60-immoware.cnf && systemctl restart mariadb || systemctl start mariadb
    # Nicht-interaktives Aequivalent zu mariadb-secure-installation. root bleibt bei unix_socket-Authentifizierung.
    mariadb --protocol=socket -e "
        DELETE FROM mysql.global_priv WHERE User='';
        DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1');
        DROP DATABASE IF EXISTS test;
        DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
        FLUSH PRIVILEGES;"
    DB_PASSWORD="$(credential_get DB_PASSWORD)"
    if [[ -z "$DB_PASSWORD" ]]; then
        DB_PASSWORD="$(random_secret)"
        credential_set DB_PASSWORD "$DB_PASSWORD"
        log "Neues Datenbankpasswort erzeugt."
    fi
    mariadb --protocol=socket -e "
        CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
        CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
        ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
        ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
        GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
        GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
        FLUSH PRIVILEGES;"
    credential_set DB_NAME "$DB_NAME"
    credential_set DB_USER "$DB_USER"
    log "MariaDB $(mariadb --version | sed 's/.*Distrib \([0-9.]*\).*/\1/'), Datenbank $DB_NAME und Nutzer $DB_USER eingerichtet."
else
    DB_PASSWORD="<zufaellig>"
fi

# ---------------------------------------------------------------------------
# 8. Redis
# ---------------------------------------------------------------------------
step "8. Redis (nur localhost, requirepass, appendonly, noeviction)"
# Paket redis-server ist Pflicht. Ubuntu 26.04 fuehrt Valkey als Standard; ein Wechsel auf valkey erfolgt nicht
# stillschweigend (Konfigurationspfade, Unit-Name und Kompatibilitaet sind im Projekt nicht getestet).
REDIS_CANDIDATE="$(apt_candidate redis-server)"
if [[ -z "$REDIS_CANDIDATE" ]]; then
    if [[ "$DRY_RUN" -eq 1 && -n "$BOOTSTRAP_FAKE_RELEASE" ]]; then
        warn "[dry-run] apt kennt hier kein redis-server (simuliertes Release). Auf dem Zielsystem bricht das Skript ab, wenn das Paket fehlt."
    else
        fail "Paket redis-server ist in den Paketquellen von Ubuntu $RELEASE_ID nicht vorhanden. Ubuntu 26.04 liefert stattdessen valkey (valkey-server, valkey-redis-compat). Kein automatischer Wechsel: Freigabe einholen, dann Skript anpassen (Paket, /etc/valkey, Unit valkey-server)."
    fi
fi
note "Redis: Paket redis-server, Kandidat ${REDIS_CANDIDATE:-keiner}$( [[ -n "$REDIS_CANDIDATE" ]] && echo ", Quelle $(apt_candidate_origin redis-server)" )."
run apt-get install -y -q redis-server
if [[ "$DRY_RUN" -eq 0 ]]; then
    [[ -f /etc/redis/redis.conf ]] || fail "/etc/redis/redis.conf fehlt nach der Installation. redis-server ist auf diesem Release vermutlich ein Uebergangspaket auf valkey (/etc/valkey). Kein automatischer Wechsel, Ruecksprache erforderlich."
    command -v redis-server >/dev/null 2>&1 || fail "Binary redis-server fehlt nach der Installation."
    if redis-server --version 2>/dev/null | grep -qi valkey; then
        warn "redis-server ist ein Valkey-Binary: $(redis-server --version). Kompatibel laut Ubuntu, im Projekt aber nicht getestet."
    fi
fi
REDIS_PASSWORD="$(credential_get REDIS_PASSWORD)"
if [[ -z "$REDIS_PASSWORD" && "$DRY_RUN" -eq 0 ]]; then
    REDIS_PASSWORD="$(random_secret)"
    credential_set REDIS_PASSWORD "$REDIS_PASSWORD"
    log "Neues Redis-Passwort erzeugt."
fi
[[ -n "$REDIS_PASSWORD" ]] || REDIS_PASSWORD="<zufaellig>"
write_file /etc/redis/immoware.conf 0640 redis:redis <<EOF
# Immoware Hub, Redis-Betriebswerte (analog compose.yaml). Wird am Ende von /etc/redis/redis.conf eingebunden.
bind 127.0.0.1
protected-mode yes
requirepass $REDIS_PASSWORD
appendonly yes
appendfsync everysec
maxmemory $REDIS_MAXMEMORY
maxmemory-policy noeviction
EOF
if [[ "$DRY_RUN" -eq 0 ]]; then
    grep -qxF 'include /etc/redis/immoware.conf' /etc/redis/redis.conf || echo 'include /etc/redis/immoware.conf' >> /etc/redis/redis.conf
    systemctl enable redis-server
    systemctl restart redis-server
    redis-cli -a "$REDIS_PASSWORD" --no-auth-warning ping | grep -q PONG || fail "Redis antwortet nicht auf PING."
    note "Redis laeuft: $(redis-server --version | sed 's/.*v=\([0-9.]*\).*/\1/')."
fi

# ---------------------------------------------------------------------------
# 9. shared/.env aus .env.example
# ---------------------------------------------------------------------------
step "9. $APP_DIR/shared/.env"
ENV_FILE="$APP_DIR/shared/.env"
if [[ -f "$ENV_FILE" ]]; then
    log ".env vorhanden, wird nicht veraendert (Secrets bleiben erhalten)."
elif [[ "$DRY_RUN" -eq 1 ]]; then
    log "[dry-run] wuerde $ENV_FILE aus .env.example erzeugen (production, DB, Redis, APP_KEY, HUB_HASH_PEPPER, alle Flags false)."
else
    # env_set <KEY> <WERT>: setzt oder ergaenzt eine Variable in der Arbeitskopie
    tmpenv="$(mktemp)"
    cp "$SRC_DIR/.env.example" "$tmpenv"
    env_set() {
        if grep -q "^$1=" "$tmpenv"; then
            sed -i "s|^$1=.*|$1=$2|" "$tmpenv"
        else
            printf '\n%s=%s\n' "$1" "$2" >> "$tmpenv"
        fi
    }
    APP_KEY="base64:$(openssl rand -base64 32)"           # gleiches Format wie php artisan key:generate (32 Byte, AES-256)
    HUB_HASH_PEPPER="$(openssl rand -hex 32)"
    env_set APP_ENV production
    env_set APP_DEBUG false
    env_set APP_KEY "$APP_KEY"
    env_set APP_URL "https://$HUB_DOMAIN"
    env_set LOG_CHANNEL daily
    env_set LOG_LEVEL info
    env_set DB_CONNECTION mariadb
    env_set DB_HOST 127.0.0.1
    env_set DB_PORT 3306
    env_set DB_DATABASE "$DB_NAME"
    env_set DB_USERNAME "$DB_USER"
    env_set DB_PASSWORD "\"$DB_PASSWORD\""
    env_set REDIS_HOST 127.0.0.1
    env_set REDIS_PORT 6379
    env_set REDIS_PASSWORD "\"$REDIS_PASSWORD\""
    env_set QUEUE_CONNECTION redis
    env_set CACHE_STORE redis
    env_set SESSION_DRIVER redis
    env_set SESSION_SECURE_COOKIE true
    env_set TRUSTED_PROXIES ""
    env_set HUB_HASH_PEPPER "$HUB_HASH_PEPPER"
    env_set HUB_DB_TRIGGERS true
    env_set MAIL_APP_DOMAIN "$MAIL_DOMAIN"
    # Alle Schreib- und Versandflags ausdruecklich false (Standard in .env.example, hier nochmals erzwungen)
    for flag in IMMOWARE_WRITE_ENABLED IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED IMMOWARE_WRITE_WEBDAV_OVERWRITE_ENABLED \
                IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED IMMOWARE_WRITE_WEBDAV_MOVE_ENABLED IMMOWARE_WRITE_CARDDAV_ENABLED \
                IMMOWARE_WRITE_CALDAV_ENABLED IMMOWARE_WRITE_DRY_RUN HUB_WEBHOOKS_ENABLED HUB_API_KEYS_ENABLED \
                MAIL_IMPORT_ENABLED MAIL_AI_ENABLED MAIL_GMAIL_DRAFTS_ENABLED MAIL_GMAIL_SEND_ENABLED \
                MAIL_IMMOWARE_WRITE_ENABLED MAIL_LEXWARE_WRITE_ENABLED; do
        env_set "$flag" false
    done
    install -m 600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$tmpenv" "$ENV_FILE"
    rm -f "$tmpenv"
    log ".env erzeugt. APP_KEY und HUB_HASH_PEPPER wurden einmalig gesetzt; php artisan key:generate darf danach nicht mehr laufen."
fi

# ---------------------------------------------------------------------------
# 10. nginx (zunaechst HTTP-only, TLS-Bloecke nach certbot)
# ---------------------------------------------------------------------------
step "10. nginx"
run apt-get install -y -q nginx
[[ -e /etc/nginx/sites-enabled/default ]] && run rm -f /etc/nginx/sites-enabled/default

# HTTP-only Server-Block: liefert die Anwendung direkt aus, bis das Zertifikat vorliegt (Health-Check, ACME-Challenge).
# write_http_only_site <domain> <datei> <mit_upstream 1|0> <default_server 1|0>
write_http_only_site() {
    local domain="$1" file="$2" with_upstream="$3" is_default="$4" upstream="" default=""
    [[ "$with_upstream" -eq 1 ]] && upstream="upstream immoware_fpm { server unix:$FPM_SOCKET; }"
    [[ "$is_default" -eq 1 ]] && default=" default_server"
    write_file "$file" 0644 root:root <<EOF
# Immoware Hub, HTTP-only Uebergangskonfiguration fuer $domain (server-bootstrap.sh). Wird nach certbot durch
# deploy/nginx/$domain.conf ersetzt. Der Hub-Block ist default_server, damit der Health-Check ueber 127.0.0.1 funktioniert.
$upstream
server {
    listen 80$default;
    listen [::]:80$default;
    server_name $domain;
    root $APP_DIR/current/public;
    index index.php;
    server_tokens off;
    client_max_body_size 34m;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    location /.well-known/acme-challenge/ { root $LETSENCRYPT_WEBROOT; }
    location ~ ^/(health|up)(/|\$) { access_log off; try_files \$uri /index.php?\$query_string; }
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \\.php\$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_hide_header X-Powered-By;
        fastcgi_pass immoware_fpm;
    }
    location ~ /\\.(?!well-known) { deny all; }
    location ~* ^/(storage|vendor)/ { deny all; }
}
EOF
}

HUB_SITE="/etc/nginx/sites-available/$HUB_DOMAIN.conf"
MAIL_SITE="/etc/nginx/sites-available/$MAIL_DOMAIN.conf"
HUB_CERT="/etc/letsencrypt/live/$HUB_DOMAIN/fullchain.pem"
MAIL_CERT="/etc/letsencrypt/live/$MAIL_DOMAIN/fullchain.pem"

install_tls_sites() {
    # Repo-Konfigurationen sind die Referenz. Der Upstream steht nur in der Hub-Datei (Kommentar in mail.muellerhv.de.conf).
    write_file "$HUB_SITE" 0644 root:root < "$SRC_DIR/deploy/nginx/$HUB_DOMAIN.conf"
    write_file "$MAIL_SITE" 0644 root:root < "$SRC_DIR/deploy/nginx/$MAIL_DOMAIN.conf"
}

if [[ -f "$HUB_CERT" && -f "$MAIL_CERT" && "$DRY_RUN" -eq 0 ]]; then
    log "Zertifikate vorhanden, TLS-Server-Bloecke aus deploy/nginx installieren."
    install_tls_sites
else
    write_http_only_site "$HUB_DOMAIN" "$HUB_SITE" 1 1
    write_http_only_site "$MAIL_DOMAIN" "$MAIL_SITE" 0 0
fi
run ln -sfn "$HUB_SITE" "/etc/nginx/sites-enabled/$HUB_DOMAIN.conf"
run ln -sfn "$MAIL_SITE" "/etc/nginx/sites-enabled/$MAIL_DOMAIN.conf"
run systemctl enable nginx
if [[ "$DRY_RUN" -eq 0 ]]; then
    nginx -t >/dev/null 2>&1 || { nginx -t; fail "nginx-Konfiguration ungueltig."; }
    systemctl restart nginx
fi

# ---------------------------------------------------------------------------
# 11. TLS mit certbot (DNS-Pruefung vorab)
# ---------------------------------------------------------------------------
step "11. TLS (certbot)"
CERTBOT_CANDIDATE="$(apt_candidate certbot)"
if [[ -z "$CERTBOT_CANDIDATE" && "$DRY_RUN" -eq 0 ]]; then
    fail "Paket certbot ist in den Paketquellen nicht vorhanden (Komponente universe aktiv?)."
fi
note "certbot: Paket certbot ${CERTBOT_CANDIDATE:-kein Kandidat}, Plugin python3-certbot-nginx (wird nicht benutzt, certonly --webroot)."
run apt-get install -y -q certbot python3-certbot-nginx
if [[ "$SKIP_TLS" -eq 1 ]]; then
    warn "--skip-tls: nginx bleibt HTTP-only. Anmeldung funktioniert erst mit TLS (SESSION_SECURE_COOKIE=true). Spaeter: Skript ohne --skip-tls erneut ausfuehren."
    NEXT_STEPS+=("TLS nachholen: ADMIN_EMAIL=... bash $0 (ohne --skip-tls), sobald DNS auf diesen Server zeigt.")
elif [[ "$DRY_RUN" -eq 1 ]]; then
    log "[dry-run] wuerde DNS pruefen und certbot certonly --webroot fuer $HUB_DOMAIN und $MAIL_DOMAIN ausfuehren."
else
    if [[ -z "$SERVER_IPV4" ]]; then
        SERVER_IPV4="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')"
    fi
    SERVER_IPV6="$(ip -6 addr show scope global 2>/dev/null | awk '/inet6/{sub(/\/.*/,"",$2); print $2; exit}')"
    [[ -n "$SERVER_IPV4" ]] || fail "Oeffentliche IPv4 nicht ermittelbar. SERVER_IPV4=... setzen."
    for domain in "$HUB_DOMAIN" "$MAIL_DOMAIN"; do
        a_records="$(dig +short A "$domain" @1.1.1.1 | tr '\n' ' ')"
        aaaa_records="$(dig +short AAAA "$domain" @1.1.1.1 | tr '\n' ' ')"
        if [[ " $a_records" != *" $SERVER_IPV4 "* ]]; then
            fail "DNS: A-Record von $domain ist '${a_records:-leer}', erwartet $SERVER_IPV4. DNS umstellen, TTL abwarten oder --skip-tls verwenden."
        fi
        if [[ -n "$aaaa_records" && ( -z "$SERVER_IPV6" || " $aaaa_records" != *" $SERVER_IPV6 "* ) ]]; then
            fail "DNS: AAAA-Record von $domain ist '$aaaa_records', der Server hat aber '${SERVER_IPV6:-keine globale IPv6}'. Let's Encrypt prueft bevorzugt IPv6. AAAA korrigieren oder entfernen."
        fi
        log "DNS ok: $domain -> $a_records${aaaa_records:+/ $aaaa_records}"
    done
    for domain in "$HUB_DOMAIN" "$MAIL_DOMAIN"; do
        if [[ -f "/etc/letsencrypt/live/$domain/fullchain.pem" ]]; then
            log "Zertifikat fuer $domain vorhanden."
            continue
        fi
        # certonly mit Webroot statt --nginx: die Server-Bloecke aus deploy/nginx bleiben unveraendert die Referenz,
        # certbot schreibt nichts in die nginx-Konfiguration. Verlaengerung ueber den certbot-Timer plus Deploy-Hook.
        certbot certonly --non-interactive --agree-tos --no-eff-email -m "$ADMIN_EMAIL" \
            --webroot -w "$LETSENCRYPT_WEBROOT" -d "$domain" --key-type ecdsa \
            || fail "certbot fuer $domain fehlgeschlagen (Port 80 von aussen erreichbar? DNS propagiert?)."
    done
    write_file /etc/letsencrypt/renewal-hooks/deploy/immoware-nginx-reload.sh 0755 root:root <<'EOF'
#!/usr/bin/env bash
# Nach jeder Zertifikatsverlaengerung nginx neu laden.
systemctl reload nginx
EOF
    systemctl enable --now certbot.timer >/dev/null 2>&1 || true
    install_tls_sites
    nginx -t >/dev/null 2>&1 || { nginx -t; fail "nginx-Konfiguration (TLS) ungueltig."; }
    systemctl reload nginx
    log "TLS aktiv fuer $HUB_DOMAIN und $MAIL_DOMAIN."
fi

# ---------------------------------------------------------------------------
# 12. systemd-Units (Hub-Worker x2, Mail-Worker high und sync, Scheduler)
# ---------------------------------------------------------------------------
step "12. systemd-Units aus deploy/systemd"
write_file "$ETC_DIR/worker.env" 0640 "root:$DEPLOY_USER" <<'EOF'
# Immoware Hub, gemeinsame Umgebung der systemd-Units (optional). Anwendungswerte stehen in shared/.env.
LANG=C.UTF-8
EOF
write_file "$ETC_DIR/mail-worker-high.env" 0640 "root:$DEPLOY_USER" <<'EOF'
# Mail-Worker high: Notfalleskalation, SLA-Pruefung, freigegebene Aktionen (docs/mail/09-deployment.md Abschnitt 4)
MAIL_WORKER_QUEUES=mail-high
MAIL_WORKER_TIMEOUT=300
EOF
write_file "$ETC_DIR/mail-worker-sync.env" 0640 "root:$DEPLOY_USER" <<'EOF'
# Mail-Worker sync: Import, Abgleich, KI-Vorschlaege (docs/mail/09-deployment.md Abschnitt 4)
MAIL_WORKER_QUEUES=mail-sync,mail-ai
MAIL_WORKER_TIMEOUT=600
EOF
if [[ "$DRY_RUN" -eq 0 ]]; then
    for unit in immoware-hub-worker@.service immoware-hub-mail-worker@.service immoware-hub-scheduler.service; do
        write_file "/etc/systemd/system/$unit" 0644 root:root < "$SRC_DIR/deploy/systemd/$unit"
    done
    if [[ -f "$SRC_DIR/deploy/systemd/immoware-hub.target" ]]; then
        write_file /etc/systemd/system/immoware-hub.target 0644 root:root < "$SRC_DIR/deploy/systemd/immoware-hub.target"
    fi
    systemctl daemon-reload
    if command -v systemd-analyze >/dev/null 2>&1; then
        systemd-analyze verify /etc/systemd/system/immoware-hub-worker@.service /etc/systemd/system/immoware-hub-mail-worker@.service \
            /etc/systemd/system/immoware-hub-scheduler.service 2>&1 | grep -v "^$" | sed 's/^/    systemd-analyze: /' || true
    fi
fi
HUB_UNITS=(immoware-hub-worker@1 immoware-hub-worker@2 immoware-hub-mail-worker@high immoware-hub-mail-worker@sync immoware-hub-scheduler)
# Nur aktivieren. Start erst nach dem ersten Deploy, sonst laufen die Units gegen ein fehlendes current-Verzeichnis.
run systemctl enable "${HUB_UNITS[@]}"

# ---------------------------------------------------------------------------
# 13. logrotate, Backup-Cron
# ---------------------------------------------------------------------------
step "13. logrotate und Backup-Cron (taeglich 02:00)"
write_file /etc/logrotate.d/immoware-hub 0644 root:root <<EOF
# Immoware Hub, Anwendungs- und Worker-Logs
$APP_DIR/shared/storage/logs/*.log $LOG_DIR/*.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    dateext
    su $DEPLOY_USER $DEPLOY_USER
    create 0640 $DEPLOY_USER $DEPLOY_USER
}
EOF
if [[ ! -f "$ETC_DIR/backup.env" ]]; then
    write_file "$ETC_DIR/backup.env" 0600 "$DEPLOY_USER:$DEPLOY_USER" <<EOF
# Immoware Hub, Umgebung fuer deploy/scripts/backup.sh (docs/operations/02-backup-restore.md Abschnitt 3)
# BACKUP_GPG_RECIPIENT ist Pflicht: Key-ID oder E-Mail des oeffentlichen GPG-Schluessels. Der private Schluessel liegt NICHT
# auf dem Server. Ohne diesen Wert bricht backup.sh ab (Cron-Log $LOG_DIR/backup.log).
BACKUP_GPG_RECIPIENT=
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASSWORD
BACKUP_DIR=$BACKUP_DIR
BACKUP_RETENTION_DAYS=35
BACKUP_STORAGE_DIR=$APP_DIR/shared/storage/app
# Offsite-Kopie, z. B. rclone copy {} remote:immoware-hub/  (leer = nur lokal)
BACKUP_OFFSITE_CMD=
EOF
else
    log "$ETC_DIR/backup.env vorhanden, unveraendert."
fi
write_file /etc/cron.d/immoware-hub-backup 0644 root:root <<EOF
# Immoware Hub, taegliches Backup 02:00 (Europe/Berlin, Systemzeitzone). Voraussetzung: BACKUP_GPG_RECIPIENT in $ETC_DIR/backup.env
# und der oeffentliche GPG-Schluessel im Schluesselbund von $DEPLOY_USER (gpg --import als $DEPLOY_USER).
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
0 2 * * * $DEPLOY_USER . $ETC_DIR/backup.env && $APP_DIR/current/deploy/scripts/backup.sh >> $LOG_DIR/backup.log 2>&1
EOF
NEXT_STEPS+=("Backup: oeffentlichen GPG-Schluessel als $DEPLOY_USER importieren und BACKUP_GPG_RECIPIENT in $ETC_DIR/backup.env setzen, dann Probelauf: sudo -u $DEPLOY_USER bash -c '. $ETC_DIR/backup.env && $APP_DIR/current/deploy/scripts/backup.sh'.")

# ---------------------------------------------------------------------------
# 14. Erster Deploy
# ---------------------------------------------------------------------------
step "14. Erster Deploy (deploy.sh $BRANCH als $DEPLOY_USER)"
if [[ "$SKIP_TLS" -eq 1 ]]; then
    HEALTH_URL="http://127.0.0.1/health"
else
    HEALTH_URL="https://$HUB_DOMAIN/health"
fi
if [[ "$SKIP_DEPLOY" -eq 1 ]]; then
    warn "--skip-deploy: deploy.sh nicht ausgefuehrt."
    NEXT_STEPS+=("Erster Deploy: sudo -u $DEPLOY_USER -H env DEPLOY_REPO=$REPO_URL HEALTH_URL=$HEALTH_URL bash -s -- $BRANCH < $SRC_DIR/deploy/scripts/deploy.sh")
elif [[ "$DRY_RUN" -eq 1 ]]; then
    log "[dry-run] wuerde ausfuehren: sudo -u $DEPLOY_USER -H env DEPLOY_REPO=$REPO_URL HEALTH_URL=$HEALTH_URL bash -s -- $BRANCH < $SRC_DIR/deploy/scripts/deploy.sh"
else
    if [[ -L "$APP_DIR/current" ]]; then
        log "current existiert bereits, Deploy wird als Update ausgefuehrt."
    fi
    # deploy.sh per stdin, weil $SRC_DIR (lokaler Checkout) fuer $DEPLOY_USER nicht lesbar sein muss; wie in .github/workflows/deploy.yml.
    sudo -u "$DEPLOY_USER" -H env DEPLOY_REPO="$REPO_URL" DEPLOY_ROOT="$APP_DIR" DEPLOY_USER="$DEPLOY_USER" HEALTH_URL="$HEALTH_URL" \
        bash -s -- "$BRANCH" < "$SRC_DIR/deploy/scripts/deploy.sh" \
        || fail "deploy.sh fehlgeschlagen. Ausgabe oben pruefen; Worker-Units sind noch nicht gestartet."
    systemctl start "${HUB_UNITS[@]}"
    sleep 3
    systemctl --no-pager --plain list-units 'immoware-hub-*' | sed 's/^/    /' || true
    log "hub:doctor nach dem Deploy:"
    sudo -u "$DEPLOY_USER" -H bash -c "cd $APP_DIR/current && php artisan hub:doctor --no-interaction" | sed 's/^/    /' || warn "hub:doctor meldet Punkte, Ausgabe oben pruefen."
fi

# ---------------------------------------------------------------------------
# Zusammenfassung
# ---------------------------------------------------------------------------
step "Zusammenfassung"
cat <<EOF
Server:            Ubuntu ${RELEASE_ID} (${RELEASE_CODENAME}), Zeitzone Europe/Berlin, ufw 22/80/443, fail2ban sshd, unattended-upgrades
PHP:               $PHP_VERSION aus $PHP_RESOLVED (fpm Pool $DEPLOY_USER, Socket $FPM_SOCKET), Composer /usr/local/bin/composer
MariaDB:           $MARIADB_SOURCE, Datenbank $DB_NAME, Nutzer $DB_USER, nur 127.0.0.1, Binlog aktiv
Redis:             127.0.0.1, requirepass, appendonly yes, maxmemory-policy noeviction
nginx:             $HUB_SITE, $MAIL_SITE $( [[ "$SKIP_TLS" -eq 1 ]] && echo "(HTTP-only)" || echo "(TLS, Let's Encrypt, certbot.timer)" )
Anwendung:         $APP_DIR (releases, shared/.env, shared/storage, current), Deploy-Nutzer $DEPLOY_USER
systemd:           ${HUB_UNITS[*]}
Backup:            /etc/cron.d/immoware-hub-backup taeglich 02:00, Umgebung $ETC_DIR/backup.env, Ziel $BACKUP_DIR
Credentials:       $CREDENTIALS_FILE (0600, nur root). Werte stehen zusaetzlich in $APP_DIR/shared/.env (0600, $DEPLOY_USER).

Naechste Schritte:
  1. Ersten Admin-Nutzer anlegen (Rolle owner), danach 2FA bei der ersten Anmeldung einrichten:
       sudo -u $DEPLOY_USER -H bash -c 'cd $APP_DIR/current && php artisan hub:user:create <email> --role=owner --name="<Name>"'
  2. hub:doctor pruefen: sudo -u $DEPLOY_USER -H bash -c 'cd $APP_DIR/current && php artisan hub:doctor'
  3. Health: curl -fsS https://$HUB_DOMAIN/health/database && curl -fsS https://$HUB_DOMAIN/health/queue && curl -fsS https://$MAIL_DOMAIN/up
  4. GitHub Actions fuer spaetere Deploys: Secrets DEPLOY_HOST, DEPLOY_USER=$DEPLOY_USER, DEPLOY_SSH_KEY (privater Teil des
     Schluessels, dessen oeffentlicher Teil in /home/$DEPLOY_USER/.ssh/authorized_keys steht) und DEPLOY_KNOWN_HOSTS
     (Ausgabe von: ssh-keyscan -t ed25519 $HUB_DOMAIN).
EOF
for s in "${NEXT_STEPS[@]:-}"; do [[ -n "$s" ]] && echo "  * $s"; done
echo
echo "Erkennungen:"
for d in "${DETECTED[@]:-}"; do [[ -n "$d" ]] && echo "  - $d"; done
echo
echo "Vollstaendige Checkliste: docs/operations/06-neuer-server.md"
