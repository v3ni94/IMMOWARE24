<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Datenbankseitige Sperren (zweite Sperrebene, docs/immoware/05-write-capabilities.md Abschnitt 2):
 * audit_logs ohne UPDATE und DELETE, write_operations mit put_attempts <= 1, ohne Rueckweg hinter
 * sent oder unknown und ausschliesslich operation webdav_create.
 *
 * Bewusste Ausnahme von der Regel "keine MariaDB-spezifischen SQL-Strings" (CLAUDE.md): Trigger sind in
 * Laravel nicht treiberneutral abbildbar. Die Migration prueft den Treiber und ueberspringt SQLite komplett;
 * dort gilt allein die Anwendungslogik. Referenz-SQL: docs/architecture/03-mariadb-triggers.sql.
 *
 * HUB_DB_TRIGGERS=false (nur Prozessumgebung) unterdrueckt die Anlage, damit Tests gegen MariaDB, die
 * Manipulationen an audit_logs simulieren, laufen koennen. In Produktion bleibt der Standard true.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    public const array SUPPORTED_DRIVERS = ['mariadb', 'mysql'];

    /** @var array<string, string> Triggername => Tabelle */
    public const array TRIGGERS = [
        'audit_logs_no_update' => 'audit_logs',
        'audit_logs_no_delete' => 'audit_logs',
        'write_operations_guard_insert' => 'write_operations',
        'write_operations_guard_update' => 'write_operations',
    ];

    public function up(): void
    {
        if (! self::triggersEnabled()) {
            return;
        }

        foreach (self::statements() as $name => $sql) {
            DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $name));
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), self::SUPPORTED_DRIVERS, true)) {
            return;
        }

        foreach (array_keys(self::TRIGGERS) as $name) {
            DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s', $name));
        }
    }

    public static function triggersEnabled(): bool
    {
        if (! in_array(DB::connection()->getDriverName(), self::SUPPORTED_DRIVERS, true)) {
            return false;
        }

        $flag = getenv('HUB_DB_TRIGGERS');

        if ($flag === false || $flag === '') {
            return true;
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Trigger-SQL, identisch mit docs/architecture/03-mariadb-triggers.sql.
     *
     * @return array<string, string>
     */
    public static function statements(): array
    {
        $signal = static fn (string $message): string => sprintf("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '%s';", $message);

        return [
            'audit_logs_no_update' => 'CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW '
                .$signal('audit_logs ist append-only: UPDATE ist verboten.'),
            'audit_logs_no_delete' => 'CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW '
                .$signal('audit_logs ist append-only: DELETE ist verboten.'),
            'write_operations_guard_insert' => 'CREATE TRIGGER write_operations_guard_insert BEFORE INSERT ON write_operations FOR EACH ROW '
                .'BEGIN '
                .'IF NEW.put_attempts > 1 THEN '.$signal('write_operations.put_attempts darf 1 nicht ueberschreiten.').' END IF; '
                ."IF NEW.operation <> 'webdav_create' THEN ".$signal('write_operations.operation darf nur webdav_create sein.').' END IF; '
                .'END',
            'write_operations_guard_update' => 'CREATE TRIGGER write_operations_guard_update BEFORE UPDATE ON write_operations FOR EACH ROW '
                .'BEGIN '
                .'IF NEW.put_attempts > 1 THEN '.$signal('write_operations.put_attempts darf 1 nicht ueberschreiten.').' END IF; '
                .'IF NEW.put_attempts < OLD.put_attempts THEN '.$signal('write_operations.put_attempts darf nicht verringert werden.').' END IF; '
                ."IF NEW.operation <> 'webdav_create' THEN ".$signal('write_operations.operation darf nur webdav_create sein.').' END IF; '
                ."IF OLD.status IN ('sent', 'unknown', 'verified') AND NEW.status IN ('pending', 'prechecked') THEN "
                .$signal('write_operations.status darf nicht hinter sent oder unknown zurueckfallen.').' END IF; '
                .'END',
        ];
    }
};
