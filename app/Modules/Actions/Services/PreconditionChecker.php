<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Support\DiffHasher;
use App\Modules\Cases\Models\CaseMessage;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Erneute Prüfung der Vorbedingungen unmittelbar vor der Ausführung: Version aktuell, Freigaben vollständig und
 * nicht abgelaufen, Ausgangszustand im Zielsystem unverändert, keine neue relevante Nachricht im Vorgang,
 * Wirksamkeitsdatum erreicht. Jede Abweichung führt zu manual_review, nie zu einer stillen Ausführung.
 */
final class PreconditionChecker
{
    public function __construct(
        private readonly ActionPolicy $policy,
        private readonly AdapterRegistry $adapters,
        private readonly DiffHasher $hasher,
    ) {}

    /**
     * @return array<int, string> Leer, wenn alle Vorbedingungen erfüllt sind.
     */
    public function check(ActionPlanVersion $version, ?int $stepIndex = null, bool $checkApprovals = true): array
    {
        $reasons = [];
        $plan = $version->plan;

        if ($plan === null) {
            return ['Planversion ohne Plan.'];
        }

        if ($version->getAttribute('superseded_at') !== null || (int) $plan->getAttribute('current_version_id') !== (int) $version->getKey()) {
            $reasons[] = 'Planversion ist nicht mehr aktuell (neuere Version vorhanden).';
        }

        if ($checkApprovals && $this->policy->isRejected($version)) {
            $reasons[] = 'Planversion wurde auf diesem Stand abgelehnt; Ausführung nur nach neuer Version und neuer Freigabe.';
        } elseif ($checkApprovals && ! $this->policy->isFullyApproved($version)) {
            $reasons[] = 'Freigabe fehlt, ist abgelaufen oder bindet an einen anderen Stand (diff_hash).';
        }

        $effective = $version->getAttribute('effective_date');

        if ($effective instanceof CarbonImmutable && $effective->startOfDay()->greaterThan(CarbonImmutable::now()->startOfDay())) {
            $reasons[] = sprintf('Wirksamkeitsdatum %s liegt in der Zukunft.', $effective->format('d.m.Y'));
        }

        $newerMessages = CaseMessage::query()
            ->where('case_id', $plan->getAttribute('case_id'))
            ->where(static function ($q) use ($version): void {
                $created = $version->getAttribute('created_at');
                $q->where('linked_at', '>', $created)->orWhere('created_at', '>', $created);
            })
            ->when($version->getAttribute('source_message_id') !== null, static fn ($q) => $q->where('message_id', '!=', $version->getAttribute('source_message_id')))
            ->exists();

        if ($newerMessages) {
            $reasons[] = 'Seit Planerstellung ist eine neue Nachricht im Vorgang eingegangen.';
        }

        $reasons = array_merge($reasons, $this->checkSourceState($version, $stepIndex));

        return $reasons;
    }

    /**
     * Liest den Ausgangszustand je Schritt erneut und vergleicht mit den gespeicherten Alt-Werten.
     *
     * @return array<int, string>
     */
    public function checkSourceState(ActionPlanVersion $version, ?int $stepIndex = null): array
    {
        $reasons = [];
        $old = (array) ($version->getAttribute('old_values') ?? []);
        $preconditions = (array) (($version->getAttribute('preconditions_json') ?? [])['steps'] ?? []);

        foreach ($version->steps() as $index => $step) {
            if ($stepIndex !== null && $index !== $stepIndex) {
                continue;
            }

            $ref = (array) ($step['external_ref'] ?? []);

            if (! isset($ref['reference_type'], $ref['external_id'])) {
                continue;
            }

            $system = TargetSystem::tryFrom((string) ($step['target_system'] ?? ''));

            if ($system === null) {
                $reasons[] = sprintf('Schritt %d: unbekanntes Zielsystem.', $index + 1);

                continue;
            }

            try {
                $current = $this->adapters->for($system)->readCurrent(['reference_type' => (string) $ref['reference_type'], 'external_id' => (string) $ref['external_id'], 'local_id' => isset($ref['local_id']) ? (int) $ref['local_id'] : null] + $ref);
            } catch (Throwable $e) {
                $reasons[] = sprintf('Schritt %d: Ausgangszustand nicht lesbar (%s).', $index + 1, class_basename($e));

                continue;
            }

            $expected = (array) ($old[$index] ?? []);
            $observed = [];

            foreach (array_keys($expected) as $field) {
                $observed[$field] = $current[$field] ?? null;
            }

            if ($this->hasher->hash($expected) !== $this->hasher->hash($observed)) {
                $reasons[] = sprintf('Schritt %d: Datensatz im Zielsystem hat sich seit Planerstellung geändert.', $index + 1);
            }

            $expectedVersion = $preconditions[$index]['external_version'] ?? null;

            if ($expectedVersion !== null && isset($current['version']) && (string) $current['version'] !== (string) $expectedVersion) {
                $reasons[] = sprintf('Schritt %d: Version im Zielsystem geändert (%s statt %s).', $index + 1, (string) $current['version'], (string) $expectedVersion);
            }
        }

        return $reasons;
    }
}
