<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Enums\AiRunStatus;
use App\Modules\Ai\Exceptions\AiBudgetExceededException;
use App\Modules\Ai\Models\AiRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Tages- und Monatsbudget (Cent und Token) aus mail_ai_runs. Bei erreichtem Budget erfolgt kein Aufruf; der Lauf
 * erhält Status budget_exceeded. Zeitgrenzen in UTC (Speicherung), Darstellung erfolgt in Europe/Berlin.
 */
final class AiBudget
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @throws AiBudgetExceededException
     */
    public function assertAvailable(int $organizationId): void
    {
        $now = CarbonImmutable::now('UTC');

        foreach (['daily' => $now->startOfDay(), 'monthly' => $now->startOfMonth()] as $period => $since) {
            $usage = $this->usageSince($organizationId, $since);
            $centsLimit = (int) $this->config->get('hub.ai.budget.'.$period.'_cents', 0);
            $tokenLimit = (int) $this->config->get('hub.ai.budget.'.$period.'_tokens', 0);

            if ($centsLimit > 0 && $usage['cents'] >= $centsLimit) {
                throw new AiBudgetExceededException($period, 'cents');
            }

            if ($tokenLimit > 0 && $usage['tokens'] >= $tokenLimit) {
                throw new AiBudgetExceededException($period, 'tokens');
            }
        }
    }

    public function isAvailable(int $organizationId): bool
    {
        try {
            $this->assertAvailable($organizationId);

            return true;
        } catch (AiBudgetExceededException) {
            return false;
        }
    }

    /**
     * @return array{cents: int, tokens: int, runs: int}
     */
    public function usageSince(int $organizationId, CarbonImmutable $since): array
    {
        $row = AiRun::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status', AiRunStatus::Succeeded->value)
            ->where('created_at', '>=', $since)
            ->selectRaw('COALESCE(SUM(cost_cents), 0) as cents, COALESCE(SUM(input_tokens), 0) + COALESCE(SUM(output_tokens), 0) as tokens, COUNT(*) as runs')
            ->first();

        return [
            'cents' => (int) ($row->cents ?? 0),
            'tokens' => (int) ($row->tokens ?? 0),
            'runs' => (int) ($row->runs ?? 0),
        ];
    }

    /**
     * Übersicht für die Oberfläche (Budget und Verbrauch heute und im Monat).
     *
     * @return array<string, array{limit_cents: int, used_cents: int, limit_tokens: int, used_tokens: int, runs: int}>
     */
    public function overview(int $organizationId): array
    {
        $now = CarbonImmutable::now('UTC');
        $result = [];

        foreach (['daily' => $now->startOfDay(), 'monthly' => $now->startOfMonth()] as $period => $since) {
            $usage = $this->usageSince($organizationId, $since);
            $result[$period] = [
                'limit_cents' => (int) $this->config->get('hub.ai.budget.'.$period.'_cents', 0),
                'used_cents' => $usage['cents'],
                'limit_tokens' => (int) $this->config->get('hub.ai.budget.'.$period.'_tokens', 0),
                'used_tokens' => $usage['tokens'],
                'runs' => $usage['runs'],
            ];
        }

        return $result;
    }
}
