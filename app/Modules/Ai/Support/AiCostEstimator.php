<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Schätzt Kosten in Cent aus Tokenzahlen, aber nur wenn Preise konfiguriert sind (hub.ai.pricing). Ohne Preis null,
 * es wird kein Betrag behauptet.
 */
final class AiCostEstimator
{
    public function __construct(private readonly Repository $config) {}

    public function isConfigured(): bool
    {
        return $this->price('input') !== null && $this->price('output') !== null;
    }

    public function estimateCents(int $inputTokens, int $outputTokens): ?int
    {
        $input = $this->price('input');
        $output = $this->price('output');

        if ($input === null || $output === null) {
            return null;
        }

        return (int) ceil(($inputTokens * $input + $outputTokens * $output) / 1_000_000);
    }

    private function price(string $kind): ?float
    {
        $value = $this->config->get('hub.ai.pricing.'.$kind.'_cents_per_million');

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
