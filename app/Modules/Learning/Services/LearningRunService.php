<?php

declare(strict_types=1);

namespace App\Modules\Learning\Services;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Services\DavClientFactory;
use App\Modules\Learning\Enums\LearningKind;
use App\Modules\Learning\Enums\LearningRunStatus;
use App\Modules\Learning\Exceptions\NoConnectionForKindException;
use App\Modules\Learning\Models\LearningRun;
use App\Modules\Security\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestriert einen Lauf der Lernphase Immoware24: passenden Konnektor wählen, lesend erkunden, mit dem
 * vorigen erfolgreichen Lauf gleicher Art vergleichen, Ergebnis in learning_runs ablegen. Schreibt nie nach
 * Immoware24. Die KI-Auswertung eines abgeschlossenen Laufs übernimmt LearningAiAdvisor.
 */
final class LearningRunService
{
    public function __construct(private readonly LearningDiffer $differ) {}

    /**
     * Legt sofort einen Lauf mit Status running an (für die Anzeige, bevor der Job endet) und gibt ihn zurück.
     */
    public function start(int $organizationId, LearningKind $kind, ?int $connectionId, ?User $triggeredBy): LearningRun
    {
        return LearningRun::query()->create([
            'organization_id' => $organizationId,
            'connection_id' => $connectionId,
            'kind' => $kind->value,
            'status' => LearningRunStatus::Running->value,
            'triggered_by' => $triggeredBy?->getKey(),
            'started_at' => now()->toImmutable(),
        ]);
    }

    /**
     * Führt den bereits angelegten Lauf aus (Job oder Konsole). Fehler werden auf dem Lauf vermerkt, nie geworfen,
     * damit ein Job nie wiederholt wird (ein Retry würde keinen zweiten Zustand erzeugen, aber unnötig Last auf
     * Immoware24 verursachen).
     */
    public function execute(LearningRun $run): LearningRun
    {
        $kind = $run->kind();

        try {
            $facts = $this->scan($run, $kind);
        } catch (Throwable $e) {
            Log::warning('Lernphase Immoware24: Lauf fehlgeschlagen', ['kind' => $kind->value, 'run_id' => $run->getKey(), 'class' => $e::class]);

            $run->forceFill([
                'status' => LearningRunStatus::Failed->value,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now()->toImmutable(),
            ])->save();

            return $run;
        }

        $previous = LearningRun::query()
            ->allOrganizations()
            ->where('organization_id', $run->getAttribute('organization_id'))
            ->where('connection_id', $run->getAttribute('connection_id'))
            ->where('kind', $kind->value)
            ->where('status', LearningRunStatus::Succeeded->value)
            ->where('id', '!=', $run->getKey())
            ->orderByDesc('id')
            ->first();

        $diff = $this->differ->diff($kind, is_array($previous?->getAttribute('facts_json')) ? $previous->getAttribute('facts_json') : null, $facts);

        $run->forceFill([
            'facts_json' => $facts,
            'diff_json' => $diff,
            'facts_fingerprint' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'previous_run_id' => $previous?->getKey(),
            'status' => LearningRunStatus::Succeeded->value,
            'finished_at' => now()->toImmutable(),
        ])->save();

        return $run;
    }

    /**
     * Wählt eine passende Connection für Art und Organisation. Ohne Angabe die erste aktive Connection des
     * passenden connector_type; Dateiexporte brauchen keine Connection.
     *
     * @throws NoConnectionForKindException
     */
    public function resolveConnection(LearningKind $kind, int $organizationId, ?int $connectionId): ?ImmowareConnection
    {
        if (! $kind->requiresConnection()) {
            return null;
        }

        $query = ImmowareConnection::query()
            ->allOrganizations()
            ->where('organization_id', $organizationId)
            ->whereIn('connector_type', $kind->connectorTypeValues());

        if ($connectionId !== null) {
            $query->whereKey($connectionId);
        }

        $connection = $query->orderBy('id')->first();

        if ($connection === null) {
            throw new NoConnectionForKindException($kind);
        }

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(LearningRun $run, LearningKind $kind): array
    {
        return match ($kind) {
            LearningKind::WebDav => App::make(WebDavStructureScanner::class)->scan($this->connectionOrFail($run)),
            LearningKind::CardDav => (new CardDavFieldUsageScanner(App::make(DavClientFactory::class), (int) $run->getAttribute('organization_id')))->scan($this->connectionOrFail($run)),
            LearningKind::CalDav => (new CalDavFieldUsageScanner(App::make(DavClientFactory::class), (int) $run->getAttribute('organization_id')))->scan($this->connectionOrFail($run)),
            LearningKind::Imports => App::make(ImportsStructureScanner::class)->scan(),
        };
    }

    private function connectionOrFail(LearningRun $run): ImmowareConnection
    {
        $connection = $run->connection()->withoutGlobalScope('organization')->first();

        if ($connection === null) {
            throw new NoConnectionForKindException($run->kind());
        }

        return $connection;
    }
}
