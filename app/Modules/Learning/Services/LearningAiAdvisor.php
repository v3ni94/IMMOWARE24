<?php

declare(strict_types=1);

namespace App\Modules\Learning\Services;

use App\Modules\Ai\Enums\AiTask;
use App\Modules\Ai\Services\AiResult;
use App\Modules\Ai\Services\AiSuggestionService;
use App\Modules\Learning\Enums\LearningKind;
use App\Modules\Learning\Models\LearningRun;
use App\Modules\Security\Models\User;

/**
 * Lässt einen abgeschlossenen Lernlauf von der KI auswerten: Vorschläge für Zuordnungsregeln oder Feldzuordnungen
 * aus den Rohbefunden und dem Vergleich zum vorigen Lauf. Läuft über dieselbe geprüfte Infrastruktur wie die
 * KI-Vorschläge der Mail-Bearbeitung (Budget, Maskierung, Schema- und Fachprüfung, Protokoll in mail_ai_runs).
 * Das Ergebnis ist stets ein Vorschlag (mail_ai_suggestions, Status proposed); es ändert keine Konfigurationsdatei.
 */
final class LearningAiAdvisor
{
    public function __construct(private readonly AiSuggestionService $suggestions) {}

    public function advise(LearningRun $run, ?User $actor = null): AiResult
    {
        $task = $this->taskFor($run->kind());
        // Ohne echten Aufrufer (Konsole ohne Sitzung) dient ein nicht gespeichertes Nutzerobjekt ausschließlich
        // der Ableitung der organization_id in AiSuggestionService::run(); case und message bleiben null, daher
        // wird dieses Objekt nirgends als handelnde Person protokolliert oder gespeichert.
        $actor ??= new User(['organization_id' => $run->getAttribute('organization_id')]);

        $result = $this->suggestions->run(
            $task,
            null,
            null,
            $actor,
            trusted: [
                'learning_kind' => $run->kind()->value,
                'facts' => $run->getAttribute('facts_json'),
                'diff' => $run->getAttribute('diff_json'),
                'current_configuration' => $this->currentConfiguration($run->kind()),
            ],
        );

        $suggestion = $result->suggestions[0] ?? null;

        if ($suggestion !== null) {
            $run->forceFill(['ai_suggestion_id' => $suggestion->getKey()])->save();
        }

        return $result;
    }

    private function taskFor(LearningKind $kind): AiTask
    {
        return match ($kind) {
            LearningKind::WebDav => AiTask::LearningDocumentRules,
            LearningKind::CardDav => AiTask::LearningContactMapping,
            LearningKind::CalDav => AiTask::LearningCalendarMapping,
            LearningKind::Imports => AiTask::LearningFieldMapping,
        };
    }

    /**
     * Ausschnitt der aktuell wirksamen Konfiguration, damit die KI nur Ergänzungen und keine Wiederholungen
     * vorschlägt. Nur Struktur, keine Zugangsdaten.
     *
     * @return array<string, mixed>
     */
    private function currentConfiguration(LearningKind $kind): array
    {
        return match ($kind) {
            LearningKind::WebDav => [
                'type_rules' => config('hub.documents.type_rules', []),
                'scan_roots' => config('hub.documents.scan.roots', []),
            ],
            LearningKind::CardDav, LearningKind::CalDav => [
                'note' => 'Keine feste Feldzuordnungskonfiguration, nur Beobachtung der Feldnutzung.',
            ],
            LearningKind::Imports => [
                'note' => 'Feldzuordnung je Format erfolgt über import_formats.column_mapping (Admin, Bereich Importe).',
            ],
        };
    }
}
