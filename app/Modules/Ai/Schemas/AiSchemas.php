<?php

declare(strict_types=1);

namespace App\Modules\Ai\Schemas;

use App\Modules\Ai\Enums\AiTask;
use Illuminate\Contracts\Config\Repository;

/**
 * JSON-Schemas der KI-Aufgaben. Alle Objekte sind geschlossen (additionalProperties false), damit die serverseitige
 * Prüfung (SchemaValidator) unerwartete Felder ablehnt. Die Schemas werden dem Anbieter als json_schema strict
 * übergeben und unabhängig davon lokal geprüft. Aufzählungen kommen aus config hub.ai (case_types, allowed_action_types).
 */
final class AiSchemas
{
    public const array PRIORITIES = ['p0', 'p1', 'p2', 'p3'];

    public const array CONFIDENCE = ['type' => 'integer', 'minimum' => 0, 'maximum' => 100];

    public function __construct(private readonly Repository $config) {}

    /**
     * @return array<string, mixed>
     */
    public function for(AiTask $task): array
    {
        return match ($task) {
            AiTask::Classify => $this->classify(),
            AiTask::Summarize => $this->summarize(),
            AiTask::Extract => $this->extract(),
            AiTask::SplitIssues => $this->splitIssues(),
            AiTask::MatchCandidates => $this->matchCandidates(),
            AiTask::DraftReply => $this->draftReply(),
            AiTask::NextSteps => $this->nextSteps(),
            AiTask::LearningDocumentRules => $this->learningDocumentRules(),
            AiTask::LearningFieldMapping => $this->learningFieldMapping(),
            AiTask::LearningContactMapping => $this->learningFieldMapping(),
            AiTask::LearningCalendarMapping => $this->learningFieldMapping(),
            AiTask::PlaybookMatch => $this->playbookMatch(),
            AiTask::PlaybookDraftSteps => $this->playbookDraftSteps(),
        };
    }

    /**
     * @return array<int, string>
     */
    public function caseTypes(): array
    {
        return array_values(array_map('strval', (array) $this->config->get('hub.ai.case_types', ['sonstiges'])));
    }

    /**
     * @return array<int, string>
     */
    public function allowedActionTypes(): array
    {
        return array_values(array_map('strval', (array) $this->config->get('hub.ai.allowed_action_types', ['no_action'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function quotes(): array
    {
        return [
            'type' => 'array',
            'maxItems' => 10,
            'items' => $this->object([
                'quote' => ['type' => 'string', 'maxLength' => 300],
                'source' => ['type' => 'string', 'enum' => ['subject', 'body', 'attachment', 'document']],
            ], ['quote', 'source']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classify(): array
    {
        return $this->object([
            'case_type' => ['type' => 'string', 'enum' => $this->caseTypes()],
            'priority' => ['type' => 'string', 'enum' => self::PRIORITIES],
            'priority_reason' => ['type' => 'string', 'maxLength' => 400],
            'confidence_percent' => self::CONFIDENCE,
            'quotes' => $this->quotes(),
            'is_multi_issue' => ['type' => 'boolean'],
        ], ['case_type', 'priority', 'priority_reason', 'confidence_percent', 'quotes', 'is_multi_issue']);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(): array
    {
        return $this->object([
            'summary' => ['type' => 'string', 'maxLength' => 1200],
            'requests' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 300]],
            'open_questions' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 300]],
            'quotes' => $this->quotes(),
        ], ['summary', 'requests', 'open_questions', 'quotes']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extract(): array
    {
        return $this->object([
            'persons' => ['type' => 'array', 'maxItems' => 20, 'items' => $this->object([
                'name' => ['type' => 'string', 'maxLength' => 200],
                'role' => ['type' => 'string', 'enum' => ['mieter', 'eigentuemer', 'verwaltungsbeirat', 'dienstleister', 'behoerde', 'anwalt', 'sonstige', 'unbekannt']],
            ], ['name', 'role'])],
            'addresses' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 300]],
            // Nur maskierte IBAN-Platzhalter, nie Klartext.
            'ibans_masked' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'format' => 'iban_masked']],
            'dates' => ['type' => 'array', 'maxItems' => 20, 'items' => $this->object([
                'date' => ['type' => 'string', 'format' => 'date'],
                'meaning' => ['type' => 'string', 'maxLength' => 200],
            ], ['date', 'meaning'])],
            'object_references' => ['type' => 'array', 'maxItems' => 20, 'items' => $this->object([
                'text' => ['type' => 'string', 'maxLength' => 300],
                'kind' => ['type' => 'string', 'enum' => ['objekt', 'einheit', 'vertrag', 'rechnung', 'aktenzeichen', 'sonstige']],
            ], ['text', 'kind'])],
            'uncertainties' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 300]],
            'missing_information' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 300]],
        ], ['persons', 'addresses', 'ibans_masked', 'dates', 'object_references', 'uncertainties', 'missing_information']);
    }

    /**
     * @return array<string, mixed>
     */
    private function splitIssues(): array
    {
        return $this->object([
            'issues' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => $this->object([
                'title' => ['type' => 'string', 'maxLength' => 200],
                'description' => ['type' => 'string', 'maxLength' => 800],
                'case_type' => ['type' => 'string', 'enum' => $this->caseTypes()],
                'quotes' => $this->quotes(),
            ], ['title', 'description', 'case_type', 'quotes'])],
        ], ['issues']);
    }

    /**
     * @return array<string, mixed>
     */
    private function matchCandidates(): array
    {
        return $this->object([
            'matches' => ['type' => 'array', 'maxItems' => 10, 'items' => $this->object([
                'candidate_id' => ['type' => 'string', 'maxLength' => 120],
                'reason' => ['type' => 'string', 'maxLength' => 400],
                'confidence_percent' => self::CONFIDENCE,
            ], ['candidate_id', 'reason', 'confidence_percent'])],
            'no_match_reason' => ['type' => ['string', 'null'], 'maxLength' => 400],
        ], ['matches', 'no_match_reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftReply(): array
    {
        return $this->object([
            // final: alle Aussagen aus verifizierten Fakten; interim: Zwischenstand; question: Rückfrage.
            'draft_kind' => ['type' => 'string', 'enum' => ['final', 'interim', 'question']],
            'subject' => ['type' => 'string', 'maxLength' => 300],
            'body' => ['type' => 'string', 'maxLength' => 6000],
            'facts_used' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 120]],
            'open_points' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 300]],
        ], ['draft_kind', 'subject', 'body', 'facts_used', 'open_points']);
    }

    /**
     * @return array<string, mixed>
     */
    private function nextSteps(): array
    {
        return $this->object([
            'steps' => ['type' => 'array', 'maxItems' => 10, 'items' => $this->object([
                'action_type' => ['type' => 'string', 'enum' => $this->allowedActionTypes()],
                'description' => ['type' => 'string', 'maxLength' => 400],
                'due_in_business_days' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 90],
                'requires_approval' => ['type' => 'boolean'],
            ], ['action_type', 'description', 'due_in_business_days', 'requires_approval'])],
        ], ['steps']);
    }

    /**
     * Lernphase Immoware24 (Modul Learning): Vorschläge für Dokument-Zuordnungsregeln (config hub.documents.type_rules
     * und assignment_rules). Reine Empfehlung, keine automatische Änderung der Konfiguration.
     *
     * @return array<string, mixed>
     */
    private function learningDocumentRules(): array
    {
        return $this->object([
            'new_rules' => ['type' => 'array', 'maxItems' => 20, 'items' => $this->object([
                'document_type' => ['type' => 'string', 'maxLength' => 60],
                'pattern_hint' => ['type' => 'string', 'maxLength' => 300],
                'reason' => ['type' => 'string', 'maxLength' => 300],
                'example_paths' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string', 'maxLength' => 300]],
            ], ['document_type', 'pattern_hint', 'reason', 'example_paths'])],
            'unmatched_folders' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 300]],
            'notes' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 300]],
        ], ['new_rules', 'unmatched_folders', 'notes']);
    }

    /**
     * Lernphase Immoware24: Vorschläge für Feldzuordnungen (WebDAV-Exporte, CardDAV, CalDAV). Reine Empfehlung,
     * die Übernahme in eine aktive Mapping-Version erfolgt weiterhin über die bestehende Freigabe (Sync-Modul).
     *
     * @return array<string, mixed>
     */
    private function learningFieldMapping(): array
    {
        return $this->object([
            'field_suggestions' => ['type' => 'array', 'maxItems' => 30, 'items' => $this->object([
                'external_field' => ['type' => 'string', 'maxLength' => 120],
                'suggested_local_field' => ['type' => 'string', 'maxLength' => 120],
                'confidence_percent' => self::CONFIDENCE,
                'reason' => ['type' => 'string', 'maxLength' => 300],
            ], ['external_field', 'suggested_local_field', 'confidence_percent', 'reason'])],
            'unmapped_fields' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 120]],
            'notes' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 300]],
        ], ['field_suggestions', 'unmapped_fields', 'notes']);
    }

    /**
     * Prozessdatenbank (Modul Playbooks): Auswahl der passenden Vorlage für einen neuen Vorgang.
     *
     * @return array<string, mixed>
     */
    private function playbookMatch(): array
    {
        return $this->object([
            'best_match' => ['type' => ['object', 'null'], 'properties' => [
                'playbook_id' => ['type' => 'string', 'maxLength' => 60],
                'confidence_percent' => self::CONFIDENCE,
                'reason' => ['type' => 'string', 'maxLength' => 400],
            ], 'required' => ['playbook_id', 'confidence_percent', 'reason'], 'additionalProperties' => false],
            'deviations' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 300]],
            'no_match_reason' => ['type' => ['string', 'null'], 'maxLength' => 400],
        ], ['best_match', 'deviations', 'no_match_reason']);
    }

    /**
     * Prozessdatenbank: Entwurf einer neuen, verallgemeinerten Prozessvorlage aus einem abgeschlossenen Vorgang.
     *
     * @return array<string, mixed>
     */
    private function playbookDraftSteps(): array
    {
        return $this->object([
            'title' => ['type' => 'string', 'maxLength' => 200],
            'steps' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 15, 'items' => $this->object([
                'action_type' => ['type' => 'string', 'enum' => $this->allowedActionTypes()],
                'description' => ['type' => 'string', 'maxLength' => 400],
                'typical_offset_hours' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 2160],
                'requires_approval' => ['type' => 'boolean'],
            ], ['action_type', 'description', 'typical_offset_hours', 'requires_approval'])],
            'notes' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 300]],
        ], ['title', 'steps', 'notes']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function object(array $properties, array $required): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
