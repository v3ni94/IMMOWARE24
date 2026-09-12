#!/usr/bin/env bash
# Immoware Hub, taegliches Datenbank-Backup mit GPG-Verschluesselung und Aufbewahrung.
#
# Umsetzung von docs/architecture/01-architecture-decision.md Abschnitt 7 (Backups) und
# docs/implementation-plan.md AP 7.4: MariaDB taeglich voll, zusaetzlich Export der Hub-eigenen
# Entscheidungstabellen (hub_decision_backups) und woechentlich die Audit-Kettenwurzel (audit_anchors).
#
# Pflicht-Umgebungsvariablen (z. B. aus /etc/immoware-hub/backup.env, Rechte 0600):
#   BACKUP_GPG_RECIPIENT   Key-ID oder E-Mail des oeffentlichen GPG-Schluessels (privater Schluessel liegt NICHT auf dem Server)
#   DB_DATABASE, DB_USERNAME, DB_PASSWORD  (DB_HOST, DB_PORT optional; Standard 127.0.0.1:3306)
# Optional:
#   BACKUP_DIR             Zielverzeichnis, Standard /var/backups/immoware-hub
#   BACKUP_RETENTION_DAYS  Aufbewahrung der Tagesbackups, Standard 35
#   BACKUP_DECISION_RETENTION_DAYS  Aufbewahrung der Entscheidungsexporte, Standard 400
#   BACKUP_OFFSITE_CMD     Befehl, der eine Datei ausser Haus kopiert, z. B. "rclone copy {} remote:immoware-hub/"
#                          ({} wird durch den Dateipfad ersetzt). Ohne Angabe bleibt das Backup lokal.
#   BACKUP_STORAGE_DIR     Pfad zu storage/app fuer den Blob-Tarball (Importdateien, Payloads), leer = ueberspringen
#
# Restore-Test-Anleitung: deploy/scripts/restore-test.sh und docs/operations/02-backup-restore.md.
# Kurzfassung Restore:
#   gpg --decrypt immoware_hub-<datum>.sql.zst.gpg | zstd -d | mariadb -h <host> -u <user> -p <leere Zieldatenbank>

set -Eeuo pipefail
umask 077

BACKUP_DIR="${BACKUP_DIR:-/var/backups/immoware-hub}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-35}"
BACKUP_DECISION_RETENTION_DAYS="${BACKUP_DECISION_RETENTION_DAYS:-400}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
WEEKDAY="$(date -u +%u)"

log()  { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
fail() { log "FEHLER: $*" >&2; exit 1; }
trap 'fail "Abbruch in Zeile $LINENO."' ERR

for v in BACKUP_GPG_RECIPIENT DB_DATABASE DB_USERNAME DB_PASSWORD; do
    [[ -n "${!v:-}" ]] || fail "Umgebungsvariable $v fehlt."
done
for bin in mariadb-dump gpg zstd find; do
    command -v "$bin" >/dev/null 2>&1 || fail "Programm fehlt: $bin"
done

gpg --list-keys "$BACKUP_GPG_RECIPIENT" >/dev/null 2>&1 || fail "GPG-Schluessel $BACKUP_GPG_RECIPIENT nicht im Schluesselbund."

mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/decisions" "$BACKUP_DIR/audit-anchors" "$BACKUP_DIR/blobs"

# Zugangsdaten nie auf der Kommandozeile (ps), sondern per temporaerer Optionsdatei
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
cat > "$CNF" <<CNF_EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USERNAME
password=$DB_PASSWORD
default-character-set=utf8mb4
CNF_EOF

DUMP_OPTS=(--defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --events
           --hex-blob --default-character-set=utf8mb4 --skip-lock-tables)

offsite() {
    if [[ -n "${BACKUP_OFFSITE_CMD:-}" ]]; then
        local cmd="${BACKUP_OFFSITE_CMD//\{\}/$1}"
        log "Offsite: $cmd"
        bash -c "$cmd"
    fi
}

encrypt() {
    # stdin -> zstd -> gpg -> Datei; Fehler in der Pipeline brechen ab (pipefail)
    zstd -q -T0 -3 | gpg --batch --yes --quiet --trust-model always --encrypt --recipient "$BACKUP_GPG_RECIPIENT" --output "$1"
}

# ---------------------------------------------------------------------------
# 1. Vollbackup der Datenbank (mit Binlog-Position fuer Point-in-Time-Recovery, sofern Binlog aktiv)
# ---------------------------------------------------------------------------
FULL="$BACKUP_DIR/daily/immoware_hub-$STAMP.sql.zst.gpg"
log "Vollbackup $DB_DATABASE -> $FULL"
if mariadb --defaults-extra-file="$CNF" -N -e "SHOW VARIABLES LIKE 'log_bin'" | grep -qi 'ON'; then
    mariadb-dump "${DUMP_OPTS[@]}" --master-data=2 "$DB_DATABASE" | encrypt "$FULL"
else
    log "Hinweis: Binlog nicht aktiv, keine Point-in-Time-Recovery moeglich (Konzept fordert kontinuierliches Binlog)."
    mariadb-dump "${DUMP_OPTS[@]}" "$DB_DATABASE" | encrypt "$FULL"
fi
sha256sum "$FULL" > "$FULL.sha256"
offsite "$FULL"; offsite "$FULL.sha256"

# ---------------------------------------------------------------------------
# 2. Hub-eigene Entscheidungen (nicht aus Payloads rekonstruierbar), taeglich, laengere Aufbewahrung
#    Tabellenliste laut Architekturentscheidung Abschnitt 7. Tabellen, die (noch) nicht existieren, werden uebersprungen.
# ---------------------------------------------------------------------------
DECISION_TABLES=(conflicts contact_merges external_mappings import_formats capabilities users api_keys webhook_endpoints
                 immoware_connections immoware_technical_users proposed_changes)
EXISTING=()
for t in "${DECISION_TABLES[@]}"; do
    if mariadb --defaults-extra-file="$CNF" -N -e "SELECT 1 FROM information_schema.tables WHERE table_schema='$DB_DATABASE' AND table_name='$t'" | grep -q 1; then
        EXISTING+=("$t")
    fi
done
if [[ ${#EXISTING[@]} -gt 0 ]]; then
    DEC="$BACKUP_DIR/decisions/hub_decision_backup-$STAMP.sql.zst.gpg"
    log "Entscheidungsexport (${EXISTING[*]}) -> $DEC"
    mariadb-dump "${DUMP_OPTS[@]}" "$DB_DATABASE" "${EXISTING[@]}" | encrypt "$DEC"
    sha256sum "$DEC" > "$DEC.sha256"
    offsite "$DEC"; offsite "$DEC.sha256"
fi

# ---------------------------------------------------------------------------
# 3. Woechentlich (Sonntag): Audit-Kettenwurzel an unveraenderlichen Speicher (Object Lock ueber BACKUP_OFFSITE_CMD)
# ---------------------------------------------------------------------------
if [[ "$WEEKDAY" == "7" ]]; then
    ANCH="$BACKUP_DIR/audit-anchors/audit_anchors-$STAMP.sql.zst.gpg"
    log "Audit-Anker -> $ANCH"
    mariadb-dump "${DUMP_OPTS[@]}" "$DB_DATABASE" audit_anchors | encrypt "$ANCH"
    sha256sum "$ANCH" > "$ANCH.sha256"
    offsite "$ANCH"; offsite "$ANCH.sha256"
fi

# ---------------------------------------------------------------------------
# 4. Blobs (Importdateien, archivierte Payloads) als Tarball, sofern konfiguriert
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
# audit-anchors werden lokal nicht geloescht (unveraenderlicher Speicher ist das Ziel)

# ---------------------------------------------------------------------------
# 6. Pruefung: Datei vorhanden, nicht leer, GPG-Paket lesbar (ohne Entschluesselung)
# ---------------------------------------------------------------------------
[[ -s "$FULL" ]] || fail "Backupdatei leer: $FULL"
gpg --batch --list-packets "$FULL" >/dev/null 2>&1 || fail "GPG-Paket nicht lesbar: $FULL"
log "Backup abgeschlossen: $(du -h "$FULL" | cut -f1) $FULL"
log "Erinnerung: Wiederherstellungstest quartalsweise mit deploy/scripts/restore-test.sh, Protokoll in docs/operations/02-backup-restore.md Abschnitt 6."
