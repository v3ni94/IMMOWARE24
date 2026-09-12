<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Modules\Ai\Enums\AiRunStatus;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;

/**
 * Ergebnis eines KI-Laufs für die Oberfläche. Ohne Erfolg sind die Vorschläge leer und der Status erklärt, warum;
 * die Bearbeitung bleibt manuell möglich.
 */
final class AiResult
{
    /**
     * @param  array<int, AiSuggestion>  $suggestions
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public readonly AiRunStatus $status,
        public readonly ?AiRun $run,
        public readonly array $suggestions = [],
        public readonly array $notes = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === AiRunStatus::Succeeded;
    }

    public function manualOnly(): bool
    {
        return ! $this->succeeded();
    }

    public function statusLabel(): string
    {
        return $this->status->label();
    }
}
