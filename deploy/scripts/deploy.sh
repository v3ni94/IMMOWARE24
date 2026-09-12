#!/usr/bin/env bash
# Immoware Hub, Deploy-Skript fuer den Betrieb ohne Docker (Release-Verzeichnisse mit Symlink "current").
#
# Aufbau auf dem Server:
#   /var/www/immoware-hub/releases/<zeitstempel>   ausgecheckter Code je Release
#   /var/www/immoware-hub/shared/.env               Konfiguration (nicht im Repository)
#   /var/www/immoware-hub/shared/storage            persistenter Storage
#   /var/www/immoware-hub/current -> releases/...   aktives Release
#
# Aufruf:  deploy/scripts/deploy.sh <git-ref>        (z. B. main oder ein Tag)
# Umgebung: DEPLOY_ROOT, DEPLOY_REPO, DEPLOY_USER, HEALTH_URL, KEEP_RELEASES koennen ueberschrieben werden.
# Bei jedem Fehler bricht das Skript ab; der Symlink wird erst nach erfolgreicher Migration umgestellt.
# Ein Rollback des Symlinks ist mit "deploy/scripts/deploy.sh --rollback" moeglich (Migrationen werden nicht
# zurueckgerollt; Datenintegritaet vor Bequemlichkeit).

set -Eeuo pipefail

DEPLOY_ROOT="${DEPLOY_ROOT:-/var/www/immoware-hub}"
DEPLOY_REPO="${DEPLOY_REPO:-git@github.com:muellerhv/IMMOWARE24.git}"
DEPLOY_USER="${DEPLOY_USER:-immoware}"
HEALTH_URL="${HEALTH_URL:-https://immoware.muellerhv.de/health}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer}"

RELEASES_DIR="$DEPLOY_ROOT/releases"
SHARED_DIR="$DEPLOY_ROOT/shared"
CURRENT_LINK="$DEPLOY_ROOT/current"

log()  { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
fail() { log "FEHLER: $*" >&2; exit 1; }

trap 'fail "Abbruch in Zeile $LINENO. Symlink current wurde nicht veraendert, sofern der Fehler vor dem Umschalten auftrat."' ERR

require() { command -v "$1" >/dev/null 2>&1 || fail "Benoetigtes Programm fehlt: $1"; }
require git; require curl; require "$PHP_BIN"; require "$COMPOSER_BIN"

if [[ "$(id -un)" != "$DEPLOY_USER" ]]; then
    fail "Bitte als Benutzer $DEPLOY_USER ausfuehren (aktuell: $(id -un))."
fi

# ---------------------------------------------------------------------------
# Rollback: Symlink auf das vorherige Release zurueckstellen
# ---------------------------------------------------------------------------
if [[ "${1:-}" == "--rollback" ]]; then
    current="$(readlink -f "$CURRENT_LINK")"
    previous="$(ls -1dt "$RELEASES_DIR"/*/ | sed 's#/$##' | grep -vx "$current" | head -n1 || true)"
    [[ -n "$previous" ]] || fail "Kein vorheriges Release vorhanden."
    log "Rollback: $current -> $previous"
    ln -sfn "$previous" "$CURRENT_LINK.tmp" && mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
    (cd "$CURRENT_LINK" && "$PHP_BIN" artisan config:cache && "$PHP_BIN" artisan route:cache && "$PHP_BIN" artisan view:cache && "$PHP_BIN" artisan queue:restart)
    log "Rollback abgeschlossen. Migrationen wurden nicht zurueckgerollt; bei Schemaaenderungen Restore aus Backup pruefen."
    exit 0
fi

REF="${1:-main}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
RELEASE_DIR="$RELEASES_DIR/$STAMP"

[[ -f "$SHARED_DIR/.env" ]] || fail "$SHARED_DIR/.env fehlt."
[[ -d "$SHARED_DIR/storage" ]] || fail "$SHARED_DIR/storage fehlt."
mkdir -p "$RELEASES_DIR"

# ---------------------------------------------------------------------------
# 1. Code holen
# ---------------------------------------------------------------------------
log "Release $STAMP aus $DEPLOY_REPO ($REF)"
git clone --quiet --depth 1 --branch "$REF" "$DEPLOY_REPO" "$RELEASE_DIR"
GIT_SHA="$(git -C "$RELEASE_DIR" rev-parse --short HEAD)"
rm -rf "$RELEASE_DIR/.git"
echo "$GIT_SHA" > "$RELEASE_DIR/RELEASE"

# ---------------------------------------------------------------------------
# 2. Shared einhaengen
# ---------------------------------------------------------------------------
ln -sfn "$SHARED_DIR/.env" "$RELEASE_DIR/.env"
rm -rf "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"
mkdir -p "$SHARED_DIR/storage/framework/cache/data" "$SHARED_DIR/storage/framework/sessions" \
         "$SHARED_DIR/storage/framework/views" "$SHARED_DIR/storage/logs" "$SHARED_DIR/storage/app/imports"

# ---------------------------------------------------------------------------
# 3. Abhaengigkeiten
# ---------------------------------------------------------------------------
cd "$RELEASE_DIR"
log "composer install --no-dev"
"$COMPOSER_BIN" install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative

# ---------------------------------------------------------------------------
# 4. Vorpruefung: Konfiguration, Flags, BootGuard (ohne Migration)
# ---------------------------------------------------------------------------
log "hub:doctor (Vorpruefung)"
"$PHP_BIN" artisan hub:doctor --no-interaction || fail "hub:doctor meldet Fehler. Deploy abgebrochen."

# ---------------------------------------------------------------------------
# 5. Wartungsmodus des laufenden Releases, Migration, Caches
# ---------------------------------------------------------------------------
# Bypass-Token fuer den Wartungsmodus zufaellig erzeugen (nicht aus dem Release-Zeitstempel ableitbar).
MAINTENANCE_SECRET="$(openssl rand -hex 16 2>/dev/null || head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"

if [[ -L "$CURRENT_LINK" ]]; then
    log "Wartungsmodus an (aktuelles Release), Bypass ueber /$MAINTENANCE_SECRET"
    (cd "$CURRENT_LINK" && "$PHP_BIN" artisan down --retry=30 --secret="$MAINTENANCE_SECRET" || true)
fi

# Worker vor der Migration anhalten: Jobs mit altem Code duerfen nicht gegen das migrierte Schema laufen.
# queue:restart signalisiert den laufenden Workern das Ende nach dem aktuellen Job; anschliessend wird auf
# das Ende der queue:work-Prozesse gewartet (supervisor oder systemd), danach migriert.
if [[ -L "$CURRENT_LINK" ]]; then
    log "queue:restart (aktuelles Release) und auf Worker-Ende warten"
    (cd "$CURRENT_LINK" && "$PHP_BIN" artisan queue:restart || true)
fi
if command -v supervisorctl >/dev/null 2>&1 && sudo -n supervisorctl status "${WORKER_SUPERVISOR_GROUP:-immoware-hub-worker:*}" >/dev/null 2>&1; then
    sudo -n supervisorctl stop "${WORKER_SUPERVISOR_GROUP:-immoware-hub-worker:*}" >/dev/null 2>&1 || true
elif command -v systemctl >/dev/null 2>&1 && sudo -n systemctl list-units --type=service --all "${WORKER_SYSTEMD_PATTERN:-immoware-hub-worker@*}" 2>/dev/null | grep -q immoware-hub-worker; then
    sudo -n systemctl stop "${WORKER_SYSTEMD_PATTERN:-immoware-hub-worker@*}" >/dev/null 2>&1 || true
fi
for attempt in $(seq 1 60); do
    if ! pgrep -f "artisan queue:work" >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" -eq 60 ]]; then
        log "Warnung: queue:work-Prozesse laufen nach 5 Minuten noch. Migration wird trotzdem gestartet."
    fi
    sleep 5
done

log "migrate --force"
"$PHP_BIN" artisan migrate --force --no-interaction

log "config:cache, route:cache, view:cache, event:cache"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

# ---------------------------------------------------------------------------
# 6. Umschalten (atomar), Worker neu starten, Wartungsmodus aus
# ---------------------------------------------------------------------------
ln -sfn "$RELEASE_DIR" "$CURRENT_LINK.tmp" && mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
log "current -> $RELEASE_DIR"

if command -v sudo >/dev/null 2>&1 && sudo -n systemctl reload php8.4-fpm >/dev/null 2>&1; then
    log "php-fpm neu geladen (OPcache geleert)"
else
    log "Hinweis: php-fpm konnte nicht neu geladen werden. Bei opcache.validate_timestamps=0 manuell 'systemctl reload php8.4-fpm' ausfuehren."
fi

"$PHP_BIN" artisan queue:restart
if command -v supervisorctl >/dev/null 2>&1 && sudo -n supervisorctl status "${WORKER_SUPERVISOR_GROUP:-immoware-hub-worker:*}" >/dev/null 2>&1; then
    sudo -n supervisorctl start "${WORKER_SUPERVISOR_GROUP:-immoware-hub-worker:*}" >/dev/null 2>&1 || log "Hinweis: Worker konnten nicht ueber supervisorctl gestartet werden."
elif command -v systemctl >/dev/null 2>&1; then
    sudo -n systemctl start "${WORKER_SYSTEMD_PATTERN:-immoware-hub-worker@*}" >/dev/null 2>&1 || true
fi
"$PHP_BIN" artisan up

# ---------------------------------------------------------------------------
# 7. Health-Check gegen die Betriebsdomain
# ---------------------------------------------------------------------------
# Deploy-Kriterium sind ausschliesslich Anwendung, Datenbank und Queue (/health/database, /health/queue).
# Der Gesamtstatus /health enthaelt auch den Immoware24-Spiegel (stale = 503) und wird nur informativ
# ausgegeben: Eine Immoware24-Stoerung oder eine neue Connection ohne Sync-Stand ist kein Deploy-Fehler.
HEALTH_BASE="${HEALTH_URL%/health}"
log "Health-Check $HEALTH_BASE/health/database und $HEALTH_BASE/health/queue"
for attempt in 1 2 3 4 5 6; do
    db_code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_BASE/health/database" || echo 000)"
    queue_code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_BASE/health/queue" || echo 000)"
    http_code="$db_code/$queue_code"
    if [[ "$db_code" == "200" && "$queue_code" == "200" ]]; then
        log "Health database und queue ok."
        overall="$(curl -sS -o /tmp/immoware-health.json -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)"
        if [[ "$overall" == "200" ]] && grep -q '"status":"ok"' /tmp/immoware-health.json; then
            log "Gesamtstatus /health ok."
        else
            log "Gesamtstatus /health: HTTP $overall. Deploy gueltig, Zustand der Immoware24-Anbindung im Monitoring pruefen (docs/operations/03-monitoring.md)."
        fi
        break
    fi
    if [[ "$attempt" -eq 6 ]]; then
        cat /tmp/immoware-health.json 2>/dev/null || true
        fail "Health-Check fehlgeschlagen (HTTP $http_code). Rollback pruefen: deploy/scripts/deploy.sh --rollback"
    fi
    log "Health noch nicht ok (HTTP $http_code), Versuch $attempt von 6, warte 10 s"
    sleep 10
done
rm -f /tmp/immoware-health.json

# ---------------------------------------------------------------------------
# 8. Alte Releases aufraeumen
# ---------------------------------------------------------------------------
cd "$RELEASES_DIR"
ls -1dt "$RELEASES_DIR"/*/ | tail -n +"$((KEEP_RELEASES + 1))" | while read -r old; do
    [[ "$(readlink -f "$old")" == "$(readlink -f "$CURRENT_LINK")" ]] && continue
    log "Entferne altes Release $old"
    rm -rf "$old"
done

log "Deploy $STAMP ($GIT_SHA) abgeschlossen."
