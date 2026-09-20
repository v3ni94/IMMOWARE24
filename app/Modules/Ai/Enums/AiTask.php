<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/**
 * Aufgaben des KI-Adapters. Jede Aufgabe hat ein JSON-Schema (AiSchemas) und fachliche Prüfregeln (BusinessRuleValidator).
 * Die Aufgaben learning_* (Modul Learning, Lernphase Immoware24) und playbook_* (Modul Playbooks, Prozessdatenbank)
 * laufen ohne Vorgang und Nachricht (case und message null) über dieselbe Infrastruktur (Änderungsvermerk 21.09.2026).
 */
enum AiTask: string
{
    case Classify = 'classify';
    case Summarize = 'summarize';
    case Extract = 'extract';
    case SplitIssues = 'split_issues';
    case MatchCandidates = 'match_candidates';
    case DraftReply = 'draft_reply';
    case NextSteps = 'next_steps';
    case LearningDocumentRules = 'learning_document_rules';
    case LearningFieldMapping = 'learning_field_mapping';
    case LearningContactMapping = 'learning_contact_mapping';
    case LearningCalendarMapping = 'learning_calendar_mapping';
    case PlaybookMatch = 'playbook_match';
    case PlaybookDraftSteps = 'playbook_draft_steps';

    public function suggestionType(): string
    {
        return match ($this) {
            self::Classify => 'classification',
            self::Summarize => 'summary',
            self::Extract => 'extraction',
            self::SplitIssues => 'split_issues',
            self::MatchCandidates => 'match_candidates',
            self::DraftReply => 'reply_draft',
            self::NextSteps => 'next_steps',
            self::LearningDocumentRules => 'learning_document_rules',
            self::LearningFieldMapping => 'learning_field_mapping',
            self::LearningContactMapping => 'learning_contact_mapping',
            self::LearningCalendarMapping => 'learning_calendar_mapping',
            self::PlaybookMatch => 'playbook_match',
            self::PlaybookDraftSteps => 'playbook_draft_steps',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Classify => 'Einordnung und Priorität',
            self::Summarize => 'Zusammenfassung',
            self::Extract => 'Entitäten',
            self::SplitIssues => 'Mehrfachanliegen',
            self::MatchCandidates => 'Zuordnungskandidaten',
            self::DraftReply => 'Antwortentwurf',
            self::NextSteps => 'Nächste Schritte',
            self::LearningDocumentRules => 'Dokumentregeln aus der Lernphase',
            self::LearningFieldMapping => 'Feldzuordnung aus der Lernphase',
            self::LearningContactMapping => 'Kontaktfelder aus der Lernphase',
            self::LearningCalendarMapping => 'Kalenderfelder aus der Lernphase',
            self::PlaybookMatch => 'Passende Prozessvorlage',
            self::PlaybookDraftSteps => 'Neuer Vorlagenentwurf',
        };
    }

    public function isLearning(): bool
    {
        return in_array($this, [self::LearningDocumentRules, self::LearningFieldMapping, self::LearningContactMapping, self::LearningCalendarMapping], true);
    }

    public function isPlaybook(): bool
    {
        return in_array($this, [self::PlaybookMatch, self::PlaybookDraftSteps], true);
    }
}
