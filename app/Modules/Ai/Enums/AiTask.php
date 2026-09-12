<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/**
 * Aufgaben des KI-Adapters. Jede Aufgabe hat ein JSON-Schema (AiSchemas) und fachliche Prüfregeln (BusinessRuleValidator).
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
        };
    }
}
