<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Schätzt Kosten in Cent aus Tokenzahlen, aber nur wenn Preise konfiguriert sind (hub.ai.pricing bzw.
 * hub.ai.anthropic.pricing je nach Anbieter). Ohne Preis null, es wird kein Betrag behauptet.
 */
final class AiCostEstimator
{
    public function __construct(private readonly Repository $config) {}

    public function isConfigured(string $provider = 'openai'): bool
    {
        return $this->price($provider, 'input') !== null && $this->price($provider, 'output') !== null;
    }

    public function estimateCents(int $inputTokens, int $outputTokens, string $provider = 'openai'): ?int
    {
        $input = $this->price($provider, 'input');
        $output = $this->price($provider, 'output');

        if ($input === null || $output === null) {
            return null;
        }

        return (int) ceil(($inputTokens * $input + $outputTokens * $output) / 1_000_000);
    }

    private function price(string $provider, string $kind): ?float
    {
        $path = $provider === 'anthropic'
            ? 'hub.ai.anthropic.pricing.'.$kind.'_cents_per_million'
            : 'hub.ai.pricing.'.$kind.'_cents_per_million';
        $value = $this->config->get($path);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
