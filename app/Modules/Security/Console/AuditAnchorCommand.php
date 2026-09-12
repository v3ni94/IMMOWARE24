<?php

declare(strict_types=1);

namespace App\Modules\Security\Console;

use App\Modules\Security\Services\AuditAnchorService;
use App\Modules\Security\Services\AuditChainVerifier;
use Illuminate\Console\Command;

final class AuditAnchorCommand extends Command
{
    protected $signature = 'audit:anchor';

    protected $description = 'Verankert den letzten Kettenwert des Auditlogs in audit_anchors (täglich).';

    /** @var array<int, string> */
    protected $aliases = ['hub:audit:anchor'];

    public function handle(AuditChainVerifier $verifier, AuditAnchorService $anchors): int
    {
        $result = $verifier->verify();

        if (! $result->valid) {
            $this->error('Anker nicht geschrieben: '.$result->message);

            return self::FAILURE;
        }

        $anchor = $anchors->anchor();

        if ($anchor === null) {
            $this->info('Kein neuer Anker nötig.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Anker für audit_logs.id %d geschrieben.', (int) $anchor->getAttribute('last_audit_id')));

        return self::SUCCESS;
    }
}
