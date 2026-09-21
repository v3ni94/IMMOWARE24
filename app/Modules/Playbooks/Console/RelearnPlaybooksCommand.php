<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Console;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Playbooks\Services\PlaybookLearningService;
use Illuminate\Console\Command;

/**
 * Baut die Prozessdatenbank aus bereits abgeschlossenen Vorgängen auf oder aktualisiert sie (Rückblick), statt
 * nur auf künftige Abschlüsse zu warten. chunkById statt Model::all() (Regel 3, große Tabelle).
 */
final class RelearnPlaybooksCommand extends Command
{
    protected $signature = 'hub:playbooks:relearn
        {--organization= : ID der Organisation, Standard: alle}
        {--case-type= : Nur diese Kategorie}
        {--limit=200 : Höchstzahl abgeschlossener Vorgänge, die betrachtet werden}';

    protected $description = 'Schlägt aus bereits abgeschlossenen Vorgängen Prozessvorlagen vor (Entwürfe, zu prüfen).';

    public function handle(PlaybookLearningService $learning): int
    {
        $query = MailCase::query()->allOrganizations()->whereNotNull('closed_at');

        if ($this->option('organization') !== null) {
            $query->where('organization_id', (int) $this->option('organization'));
        }

        if ($this->option('case-type') !== null) {
            $query->where('case_type', (string) $this->option('case-type'));
        }

        $limit = max(1, (int) $this->option('limit'));
        $seen = 0;

        $query->chunkById(50, function ($cases) use ($learning, $limit, &$seen): bool {
            foreach ($cases as $case) {
                $learning->learnFromClosedCase($case);
                $seen++;

                if ($seen >= $limit) {
                    return false;
                }
            }

            return true;
        });

        $this->info(sprintf('%d abgeschlossene Vorgänge betrachtet. Neue Entwürfe erscheinen in der Prozessdatenbank zur Prüfung.', $seen));

        return self::SUCCESS;
    }
}
