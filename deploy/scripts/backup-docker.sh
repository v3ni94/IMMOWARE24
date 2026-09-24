#!/usr/bin/env bash
# Immoware Hub, taegliches Datenbank-Backup im Docker-Compose-Betrieb (Variante A, deploy/scripts/server-bootstrap-docker.sh).
#
# Gegenstueck zu deploy/scripts/backup.sh (Host-Installation): gleiche Ablage, gleiche Verschluesselung (zstd, GPG), gleiche
# Aufbewahrung. Unterschied: mariadb-dump laeuft im Container mariadb ueber "docker compose exec", die Zugangsdaten kommen
# aus der Umgebung des Containers (MARIADB_USER, MARIADB_PASSWORD, MARIADB_DATABASE aus compose.yaml), nichts steht auf
# der Kommandozeile des Hosts. gpg, zstd und tar laufen auf dem Host als root (Cron /etc/cron.d/immoware-hub-backup).
#
# Pflicht-Umgebungsvariablen (z. B. aus /etc/immoware-hub/backup.env, Rechte 0600):
#   BACKUP_GPG_RECIPIENT   Key-ID oder E-Mail des oeffentlichen GPG-Schluessels (privater Schluessel liegt NICHT auf dem Server)
# Optional:
#   APP_DIR                Compose-Projektverzeichnis, Standard /opt/immoware-hub
#   BACKUP_DIR             Zielverzeichnis, Standard /var/backups/immoware-hub
#   BACKUP_RETENTION_DAYS  Aufbewahrung der Tagesbackups, Standard 35
#   BACKUP_DECISION_RETENTION_DAYS  Aufbewahrung der Entscheidungsexporte, Standard 400
#   BACKUP_OFFSITE_CMD     Befehl fuer die Offsite-Kopie, z. B. "rclone copy {} remote:immoware-hub/" ({} = Dateipfad)
#   BACKUP_STORAGE_DIR     Pfad zu storage/app auf dem Host (Bind-Volume), leer = ueberspringen
#
# Restore-Kurzfassung (docs/operations/02-backup-restore.md):
#   gpg --decrypt immoware_hub-<datum>.sql.zst.gpg | zstd -d \
#     | docker compose --project-directory /opt/immoware-hub exec -T mariadb sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u"$MARIADB_USER" <leere Zieldatenbank>'

set -Eeuo pipefail
umask 077

APP_DIR="${APP_DIR:-/opt/immoware-hub}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/immoware-hub}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-35}"
BACKUP_DECISION_RETENTION_DAYS="${BACKUP_DECISION_RETENTION_DAYS:-400}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
WEEKDAY="$(date -u +%u)"

log()  { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
fail() { log "FEHLER: $*" >&2; exit 1; }
trap 'fail "Abbruch in Zeile $LINENO."' ERR

[[ -n "${BACKUP_GPG_RECIPIENT:-}" ]] || fail "Umgebungsvariable BACKUP_GPG_RECIPIENT fehlt."
for bin in docker gpg zstd find tar; do
    command -v "$bin" >/dev/null 2>&1 || fail "Programm fehlt: $bin"
done
[[ -f "$APP_DIR/compose.yaml" ]] || fail "compose.yaml fehlt in $APP_DIR."
gpg --list-keys "$BACKUP_GPG_RECIPIENT" >/dev/null 2>&1 || fail "GPG-Schluessel $BACKUP_GPG_RECIPIENT nicht im Schluesselbund."

compose() { docker compose --project-directory "$APP_DIR" "$@"; }

# Container muss laufen und healthy sein, sonst kein konsistenter Dump
state="$(compose ps --format '{{.Service}} {{.Health}}' 2>/dev/null | awk '$1=="mariadb"{print $2}')"
[[ "$state" == "healthy" ]] || fail "Container mariadb ist nicht healthy (Status: ${state:-nicht gestartet})."

# Zugangsdaten kommen aus der Container-Umgebung (MARIADB_USER, MARIADB_PASSWORD); sh im Container expandiert sie, das
# Passwort steht ueber MYSQL_PWD weder auf der Host- noch auf der Container-Kommandozeile.
DB_DATABASE="$(compose exec -T mariadb sh -c 'printf %s "$MARIADB_DATABASE"')"
[[ -n "$DB_DATABASE" ]] || fail "MARIADB_DATABASE im Container nicht gesetzt."

DUMP_OPTS=(--single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4 --skip-lock-tables)
dump() {
    compose exec -T mariadb sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb-dump -u"$MARIADB_USER" "$@"' -- "${DUMP_OPTS[@]}" "$@"
}
query() {
    compose exec -T mariadb sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u"$MARIADB_USER" -N -e "$1"' -- "$1"
}

mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/decisions" "$BACKUP_DIR/audit-anchors" "$BACKUP_DIR/blobs"

offsite() {
    if [[ -n "${BACKUP_OFFSITE_CMD:-}" ]]; then
        local cmd="${BACKUP_OFFSITE_CMD//\{\}/$1}"
        log "Offsite: $cmd"
        bash -c "$cmd"
    fi
}

encrypt() {
    zstd -q -T0 -3 | gpg --batch --yes --quiet --trust-model always --encrypt --recipient "$BACKUP_GPG_RECIPIENT" --output "$1"
}

# ---------------------------------------------------------------------------
# 1. Vollbackup der Datenbank (mit Binlog-Position, sofern Binlog aktiv; compose.yaml startet MariaDB mit --log-bin)
# ---------------------------------------------------------------------------
FULL="$BACKUP_DIR/daily/immoware_hub-$STAMP.sql.zst.gpg"
log "Vollbackup $DB_DATABASE (Container mariadb) -> $FULL"
if query "SHOW VARIABLES LIKE 'log_bin'" | grep -qi 'ON'; then
    dump --master-data=2 "$DB_DATABASE" | encrypt "$FULL"
else
    log "Hinweis: Binlog nicht aktiv, keine Point-in-Time-Recovery moeglich."
    dump "$DB_DATABASE" | encrypt "$FULL"
fi
sha256sum "$FULL" > "$FULL.sha256"
offsite "$FULL"; offsite "$FULL.sha256"

# ---------------------------------------------------------------------------
# 2. Hub-eigene Entscheidungen (Architekturentscheidung Abschnitt 7), laengere Aufbewahrung
# ---------------------------------------------------------------------------
DECISION_TABLES=(conflicts contact_merges external_mappings import_formats capabilities users api_keys webhook_endpoints
                 immoware_connections immoware_technical_users proposed_changes)
EXISTING=()
for t in "${DECISION_TABLES[@]}"; do
    if query "SELECT 1 FROM information_schema.tables WHERE table_schema='$DB_DATABASE' AND table_name='$t'" | grep -q 1; then
        EXISTING+=("$t")
    fi
done
if [[ ${#EXISTING[@]} -gt 0 ]]; then
    DEC="$BACKUP_DIR/decisions/hub_decision_backup-$STAMP.sql.zst.gpg"
    log "Entscheidungsexport (${EXISTING[*]}) -> $DEC"
    dump "$DB_DATABASE" "${EXISTING[@]}" | encrypt "$DEC"
    sha256sum "$DEC" > "$DEC.sha256"
    offsite "$DEC"; offsite "$DEC.sha256"
fi

# ---------------------------------------------------------------------------
# 3. Woechentlich (Sonntag): Audit-Kettenwurzel
# ---------------------------------------------------------------------------
if [[ "$WEEKDAY" == "7" ]]; then
    ANCH="$BACKUP_DIR/audit-anchors/audit_anchors-$STAMP.sql.zst.gpg"
    log "Audit-Anker -> $ANCH"
    dump "$DB_DATABASE" audit_anchors | encrypt "$ANCH"
    sha256sum "$ANCH" > "$ANCH.sha256"
    offsite "$ANCH"; offsite "$ANCH.sha256"
fi

# ---------------------------------------------------------------------------
# 4. Blobs (Importdateien, archivierte Payloads) vom Bind-Volume auf dem Host
# ---------------------------------------------------------------------------
if [[ -n "${BACKUP_STORAGE_DIR:-}" && -d "$BACKUP_STORAGE_DIR" ]]; then
    BLOB="$BACKUP_DIR/blobs/storage-app-$STAMP.tar.zst.gpg"
    log "Blobs $BACKUP_STORAGE_DIR -> $BLOB"
    tar -C "$BACKUP_STORAGE_DIR" -cf - . | encrypt "$BLOB"
    sha256sum "$BLOB" > "$BLOB.sha256"
    offsite "$BLOB"; offsite "$BLOB.sha256"
fi

# ---------------------------------------------------------------------------
# 5. Aufbewahrung
# ---------------------------------------------------------------------------
find "$BACKUP_DIR/daily" "$BACKUP_DIR/blobs" -type f -mtime +"$BACKUP_RETENTION_DAYS" -print -delete | sed 's/^/Geloescht: /' || true
find "$BACKUP_DIR/decisions" -type f -mtime +"$BACKUP_DECISION_RETENTION_DAYS" -print -delete | sed 's/^/Geloescht: /' || true

# ---------------------------------------------------------------------------
# 6. Pruefung
# ---------------------------------------------------------------------------
[[ -s "$FULL" ]] || fail "Backupdatei leer: $FULL"
# gpg --list-packets versucht bei Public-Key-Verschluesselung zusaetzlich den Session-Key zu entschluesseln und liefert
# ohne privaten Schluessel (bewusst nicht auf dem Server) einen Fehler-Exitcode, obwohl die Datei korrekt ist. Deshalb
# wird der Exitcode von gpg hier bewusst ignoriert (set -o pipefail wuerde ihn sonst durchreichen) und nur die
# Paketstruktur in der Ausgabe geprueft.
PACKET_LISTING="$(gpg --batch --list-packets "$FULL" 2>/dev/null || true)"
grep -q '^:pubkey enc packet:' <<< "$PACKET_LISTING" || fail "GPG-Paket nicht lesbar: $FULL"
log "Backup abgeschlossen: $(du -h "$FULL" | cut -f1) $FULL"
log "Erinnerung: Wiederherstellungstest quartalsweise, Protokoll in docs/operations/02-backup-restore.md Abschnitt 6."
