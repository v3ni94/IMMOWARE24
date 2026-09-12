-- 03 MariaDB-Trigger des Immoware Hub (Stand 12.09.2026)
--
-- Referenz-SQL der Migration database/migrations/2026_09_12_130000_add_mariadb_integrity_triggers.php.
-- Die Migration legt genau diese Trigger an, wenn der Datenbanktreiber mariadb oder mysql ist.
-- SQLite (Tests) erhaelt keine Trigger; dort gilt ausschliesslich die Anwendungslogik
-- (AuditLog-Model ohne Update/Delete, WriteOperation::saving mit WriteBlockedException).
--
-- Die Trigger sind die zweite Sperrebene aus docs/immoware/05-write-capabilities.md Abschnitt 2.
-- Nicht als Trigger umgesetzt (nur Anwendungslogik, siehe Abgleich 12.09.2026 in 02-data-model.md):
-- write_enabled-Vier-Augen-Pruefung, capabilities.enabled gegen evidence_status, purpose-Kopplung an
-- immoware_technical_users, contact_merges -> contacts.merged_into_id.
--
-- Abschalten (nur Tests gegen MariaDB, die Manipulationen simulieren): Umgebungsvariable HUB_DB_TRIGGERS=false
-- vor php artisan migrate. In Produktion bleibt der Standard true.

-- 1. audit_logs ist append-only (CLAUDE.md Regel 7, 08-security.md Abschnitt 6).
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs ist append-only: UPDATE ist verboten.';

CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs ist append-only: DELETE ist verboten.';

-- 2. write_operations: hoechstens ein PUT je Operation, kein Rueckweg hinter sent oder unknown,
--    Operation ausschliesslich webdav_create (05-write-capabilities.md Abschnitt 3.3).
CREATE TRIGGER write_operations_guard_insert BEFORE INSERT ON write_operations FOR EACH ROW
BEGIN
    IF NEW.put_attempts > 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'write_operations.put_attempts darf 1 nicht ueberschreiten.';
    END IF;
    IF NEW.operation <> 'webdav_create' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'write_operations.operation darf nur webdav_create sein.';
    END IF;
END;

CREATE TRIGGER write_operations_guard_update BEFORE UPDATE ON write_operations FOR EACH ROW
BEGIN
    IF NEW.put_attempts > 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'write_operations.put_attempts darf 1 nicht ueberschreiten.';
    END IF;
    IF NEW.put_attempts < OLD.put_attempts THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'write_operations.put_attempts darf nicht verringert werden.';
    END IF;
    IF NEW.operation <> 'webdav_create' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'write_operations.operation darf nur webdav_create sein.';
    END IF;
    IF OLD.status IN ('sent', 'unknown', 'verified') AND NEW.status IN ('pending', 'prechecked') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'write_operations.status darf nicht hinter sent oder unknown zurueckfallen.';
    END IF;
END;
