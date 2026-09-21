<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\DTO;

use App\Modules\Playbooks\Enums\MatchOutcome;
use App\Modules\Playbooks\Models\Playbook;
use App\Modules\Playbooks\Models\PlaybookMatch;

/**
 * Ergebnis eines Abgleichs für die Oberfläche und für Folgeverarbeitung.
 */
final readonly class MatchResult
{
    /**
     * @param  array<int, string>  $deviations
     */
    public function __construct(
        public PlaybookMatch $match,
        public ?Playbook $playbook,
        public MatchOutcome $outcome,
        public ?int $similarityScore,
        public array $deviations = [],
        public ?Playbook $draftedPlaybook = null,
    ) {}
}
