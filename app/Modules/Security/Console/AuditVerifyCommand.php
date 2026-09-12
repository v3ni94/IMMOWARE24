<?php

declare(strict_types=1);

namespace App\Modules\Security\Console;

use App\Modules\Security\Services\AuditChainVerifier;
use Illuminate\Console\Command;

final class AuditVerifyCommand extends Command
{
    protected $signature = 'audit:verify {--from= : Prüfung ab dieser audit_logs.id}';

    protected $description = 'Prüft die Hash-Kette des Auditlogs und die gespeicherten Anker.';

    /** @var array<int, string> */
    protected $aliases = ['hub:audit:verify'];

    public function handle(AuditChainVerifier $verifier): int
    {
        $from = $this->option('from') !== null ? (int) $this->option('from') : null;
        $result = $verifier->verify($from);

        if (! $result->valid) {
            $this->error((string) $result->message);

            return self::FAILURE;
        }

        $this->info((string) $result->message);
        $this->line('Letzter Kettenwert: '.$result->lastHash);

        return self::SUCCESS;
    }
}
