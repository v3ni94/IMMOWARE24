<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spiegeldaten werden nur über deleted_at entfernt (CLAUDE.md Regel 3). Die Kaskaden auf mail_mailboxes und
 * mail_messages (Aliasse, Postfachrechte, Sync-Zustände, Push-Ereignisse, Nachrichtenteile) würden bei einem
 * forceDelete oder direkten DELETE in MariaDB Nachrichtenteile und Sync-Zustände mitlöschen. Sie werden auf
 * restrictOnDelete umgestellt; Löschungen laufen ausschließlich über SoftDeletes und den noch offenen
 * Retention-Befehl (docs/mail/09-deployment.md). SQLite (Tests) kennt kein Ändern von Fremdschlüsseln und setzt sie
 * standardmäßig nicht durch; dort ist die Migration ein No-op.
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, column: string, references: string}> */
    private const array FOREIGN_KEYS = [
        ['table' => 'mail_mailbox_aliases', 'column' => 'mailbox_id', 'references' => 'mail_mailboxes'],
        ['table' => 'mail_mailbox_permissions', 'column' => 'mailbox_id', 'references' => 'mail_mailboxes'],
        ['table' => 'mail_sync_states', 'column' => 'mailbox_id', 'references' => 'mail_mailboxes'],
        ['table' => 'mail_push_events', 'column' => 'mailbox_id', 'references' => 'mail_mailboxes'],
        ['table' => 'mail_message_parts', 'column' => 'message_id', 'references' => 'mail_messages'],
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::FOREIGN_KEYS as $key) {
            Schema::table($key['table'], static function (Blueprint $table) use ($key): void {
                $table->dropForeign([$key['column']]);
                $table->foreign($key['column'])->references('id')->on($key['references'])->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::FOREIGN_KEYS as $key) {
            Schema::table($key['table'], static function (Blueprint $table) use ($key): void {
                $table->dropForeign([$key['column']]);
                $table->foreign($key['column'])->references('id')->on($key['references'])->cascadeOnDelete();
            });
        }
    }
};
