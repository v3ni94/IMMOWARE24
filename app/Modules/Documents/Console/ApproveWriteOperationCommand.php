<?php

declare(strict_types=1);

namespace App\Modules\Documents\Console;

use App\Modules\Documents\Services\PosteingangUploadService;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Models\WriteOperation;
use Illuminate\Console\Command;
use Throwable;

/**
 * Menschliche Freigabe eines pending-Upload-Antrags (requested_via api_key, mcp, n8n) durch einen Nutzer der
 * Rolle operator oder höher (09-api-documentation.md 3.5, 08-security.md Abschnitt 8). Der Inhalt kommt aus dem
 * Blob-Speicher; Flags und Connection-Freigabe werden erneut geprüft.
 */
final class ApproveWriteOperationCommand extends Command
{
    protected $signature = 'hub:write:approve {operation : ID der write_operation} {--user= : ID des freigebenden Nutzers} {--dry-run : nur Precheck, kein PUT}';

    protected $description = 'Gibt einen pending-Upload-Antrag aus einem API-Key-Kontext frei und führt ihn aus.';

    public function handle(PosteingangUploadService $service): int
    {
        $operation = WriteOperation::query()->find((int) $this->argument('operation'));

        if ($operation === null) {
            $this->error('write_operation nicht gefunden.');

            return self::FAILURE;
        }

        $user = User::query()->allOrganizations()->find((int) $this->option('user'));

        if (! $user instanceof User) {
            $this->error('Freigebender Nutzer (--user) nicht gefunden.');

            return self::FAILURE;
        }

        try {
            $result = $service->approve($operation, $user, null, (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $this->error('Freigabe abgelehnt: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('write_operation %d: %s -> %s', (int) $operation->getKey(), $result->outcome, $result->status()->value));

        return self::SUCCESS;
    }
}
