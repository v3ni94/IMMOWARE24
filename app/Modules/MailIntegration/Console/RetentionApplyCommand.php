<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Console;

use App\Modules\MailIntegration\Services\RetentionService;
use Illuminate\Console\Command;

/**
 * Aufbewahrungsregeln anwenden (docs/mail/08-datenschutz-sicherheit.md Abschnitt 6). Ohne --apply läuft die Vorschau
 * (Dry Run) und zählt nur. Legal Hold auf einem Vorgang verhindert jede Löschung an ihm und seinen Daten. Der Lauf ist
 * auditiert (Anzahl, Frist, Regel). Nächtlicher Zeitplan in routes/console.php, nur bei MAIL_RETENTION_ENABLED=true.
 */
final class RetentionApplyCommand extends Command
{
    protected $signature = 'mail:retention:apply
        {--apply : Löschungen tatsächlich ausführen (Standard ist die Vorschau)}
        {--dry-run : Ausdrücklich nur zählen, überstimmt --apply}
        {--organization= : Nur diese Organisation (ID)}';

    protected $description = 'Aufbewahrungsregeln der Mail-Bearbeitung anwenden: Nachrichtentexte, Anhänge, KI-Läufe, Push-Ereignisse, abgeschlossene Vorgänge. Ohne --apply nur Vorschau.';

    public function handle(RetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('apply');
        $organization = $this->option('organization');
        $organizationIds = $organization !== null && $organization !== '' ? [(int) $organization] : $retention->organizationIds();

        $this->line($dryRun ? 'Vorschau (Dry Run): es wird nichts gelöscht.' : 'Anwendung: Löschungen werden ausgeführt und auditiert.');

        if (! $retention->isEnabled() && ! $dryRun) {
            $this->warn('MAIL_RETENTION_ENABLED ist nicht gesetzt. Der manuelle Lauf wird dennoch ausgeführt; der Zeitplan bleibt aus.');
        }

        $rows = [];

        foreach ($organizationIds as $organizationId) {
            foreach ($retention->apply($organizationId, $dryRun) as $rule => $row) {
                $rows[] = [(string) $organizationId, $row['label'], (string) $row['days'], (string) $row['candidates'], (string) $row['held'], (string) $row['applied']];
            }
        }

        $push = $retention->applyPushEvents($dryRun);
        $rows[] = ['alle', $push['label'], (string) $push['days'], (string) $push['candidates'], (string) $push['held'], (string) $push['applied']];

        $this->table(['Organisation', 'Regel', 'Frist (Tage)', 'Kandidaten', 'Gesperrt (Legal Hold, offen)', 'Angewendet'], $rows);

        return self::SUCCESS;
    }
}
