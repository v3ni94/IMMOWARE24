#!/usr/bin/env bash
# Immoware Hub, Wiederherstellungstest (Vollrestore) in eine getrennte Pruefdatenbank.
#
# Zweck: quartalsweiser Nachweis, dass ein verschluesseltes Backup entschluesselbar, vollstaendig und
# migrationskonsistent ist (docs/implementation-plan.md AP 7.5). Produktion wird NICHT beruehrt.
#
# Aufruf: deploy/scripts/restore-test.sh <backupdatei.sql.zst.gpg>
# Umgebung (z. B. /etc/immoware-hub/restore-test.env, 0600):
#   RESTORE_DB_HOST, RESTORE_DB_PORT (Standard 127.0.0.1:3306)
#   RESTORE_DB_ADMIN_USER, RESTORE_DB_ADMIN_PASSWORD   Nutzer mit CREATE/DROP-Recht auf der Pruefinstanz (nie Produktion)
#   RESTORE_DB_NAME  Standard immoware_hub_restoretest
#   APP_DIR          Pfad zum ausgecheckten Hub-Code fuer migrate:status und audit:verify (optional)
#   KEEP_RESTORE_DB=1  Pruefdatenbank nach dem Test behalten
# Der private GPG-Schluessel muss im Schluesselbund des ausfuehrenden Nutzers liegen (Offline-Schluessel, nur fuer den Test einhaengen).

set -Eeuo pipefail
umask 077

BACKUP_FILE="${1:-}"
[[ -n "$BACKUP_FILE" && -f "$BACKUP_FILE" ]] || { echo "Aufruf: $0 <backupdatei.sql.zst.gpg>" >&2; exit 2; }

RESTORE_DB_HOST="${RESTORE_DB_HOST:-127.0.0.1}"
RESTORE_DB_PORT="${RESTORE_DB_PORT:-3306}"
RESTORE_DB_NAME="${RESTORE_DB_NAME:-immoware_hub_restoretest}"
: "${RESTORE_DB_ADMIN_USER:?RESTORE_DB_ADMIN_USER fehlt}"
: "${RESTORE_DB_ADMIN_PASSWORD:?RESTORE_DB_ADMIN_PASSWORD fehlt}"

log()  { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
fail() { log "FEHLER: $*" >&2; exit 1; }
trap 'fail "Abbruch in Zeile $LINENO."' ERR

for bin in mariadb gpg zstd sha256sum; do command -v "$bin" >/dev/null 2>&1 || fail "Programm fehlt: $bin"; done

case "$RESTORE_DB_NAME" in
    *restoretest*) ;;
    *) fail "Sicherheitsregel: RESTORE_DB_NAME muss 'restoretest' enthalten (aktuell: $RESTORE_DB_NAME)." ;;
esac

CNF="$(mktemp)"; trap 'rm -f "$CNF"' EXIT
cat > "$CNF" <<CNF_EOF
[client]
host=$RESTORE_DB_HOST
port=$RESTORE_DB_PORT
user=$RESTORE_DB_ADMIN_USER
password=$RESTORE_DB_ADMIN_PASSWORD
default-character-set=utf8mb4
CNF_EOF
M=(mariadb --defaults-extra-file="$CNF")

START="$(date +%s)"
log "Wiederherstellungstest: $BACKUP_FILE -> $RESTORE_DB_NAME@$RESTORE_DB_HOST"

# 1. Pruefsumme
if [[ -f "$BACKUP_FILE.sha256" ]]; then
    (cd "$(dirname "$BACKUP_FILE")" && sha256sum -c "$(basename "$BACKUP_FILE").sha256") || fail "Pruefsumme stimmt nicht."
    log "Pruefsumme ok."
else
    log "Hinweis: keine .sha256 neben dem Backup."
fi

# 2. Zieldatenbank frisch anlegen
"${M[@]}" -e "DROP DATABASE IF EXISTS \`$RESTORE_DB_NAME\`; CREATE DATABASE \`$RESTORE_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
log "Datenbank $RESTORE_DB_NAME angelegt."

# 3. Entschluesseln, entpacken, einspielen (Streaming, keine Klartextdatei auf Platte)
gpg --batch --quiet --decrypt "$BACKUP_FILE" | zstd -d -q | "${M[@]}" "$RESTORE_DB_NAME"
log "Einspielen abgeschlossen."

# 4. Plausibilitaet: Tabellen, Kernzeilen, letzte Migration
TABLES="$("${M[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$RESTORE_DB_NAME'")"
[[ "$TABLES" -gt 0 ]] || fail "Keine Tabellen wiederhergestellt."
log "Tabellen: $TABLES"
for t in migrations users audit_logs immoware_connections sync_states documents contacts write_operations; do
    if "${M[@]}" -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='$RESTORE_DB_NAME' AND table_name='$t'" | grep -q 1; then
        n="$("${M[@]}" -N -e "SELECT COUNT(*) FROM \`$RESTORE_DB_NAME\`.\`$t\`")"
        log "  $t: $n Zeilen"
    fi
done
LAST_MIGRATION="$("${M[@]}" -N -e "SELECT migration FROM \`$RESTORE_DB_NAME\`.migrations ORDER BY id DESC LIMIT 1" 2>/dev/null || echo unbekannt)"
log "Letzte Migration im Backup: $LAST_MIGRATION"

# 5. Optional: Anwendung gegen die Pruefdatenbank laufen lassen (migrate:status, Audit-Hash-Kette)
if [[ -n "${APP_DIR:-}" && -f "$APP_DIR/artisan" ]]; then
    log "migrate:status und audit:verify gegen $RESTORE_DB_NAME"
    (
        cd "$APP_DIR"
        export DB_CONNECTION=mariadb DB_HOST="$RESTORE_DB_HOST" DB_PORT="$RESTORE_DB_PORT" DB_DATABASE="$RESTORE_DB_NAME" \
               DB_USERNAME="$RESTORE_DB_ADMIN_USER" DB_PASSWORD="$RESTORE_DB_ADMIN_PASSWORD" \
               CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array
        php artisan migrate:status --no-ansi | tail -n 5
        php artisan migrate:status --no-ansi | grep -q 'Pending' && log "Hinweis: ausstehende Migrationen, Backup stammt von aelterem Code." || true
        php artisan audit:verify --no-ansi || fail "Audit-Hash-Kette im Restore nicht konsistent."
    )
else
    log "APP_DIR nicht gesetzt, Anwendungspruefung uebersprungen."
fi

# 6. Aufraeumen
if [[ "${KEEP_RESTORE_DB:-0}" != "1" ]]; then
    "${M[@]}" -e "DROP DATABASE \`$RESTORE_DB_NAME\`;"
    log "Pruefdatenbank entfernt."
fi

DURATION=$(( $(date +%s) - START ))
log "Wiederherstellungstest erfolgreich. Dauer ${DURATION}s. Ergebnis in docs/operations/02-backup-restore.md Abschnitt 6 protokollieren (Datum, Backupdatei, Tabellen, Dauer, Tester)."
