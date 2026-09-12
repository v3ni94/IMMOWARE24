<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/**
 * Status eines KI-Laufs (mail_ai_runs.status). Nur succeeded liefert Vorschläge; alle anderen Zustände lassen die
 * Bearbeitung manuell offen und werden in der Oberfläche als Hinweis, nie als Erfolg, angezeigt.
 */
enum AiRunStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case SchemaInvalid = 'schema_invalid';
    case RuleRejected = 'rule_rejected';
    case Failed = 'failed';
    case BudgetExceeded = 'budget_exceeded';
    case NotConfigured = 'not_configured';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Läuft',
            self::Succeeded => 'Vorschlag liegt vor',
            self::SchemaInvalid => 'Antwort verworfen (Schema)',
            self::RuleRejected => 'Antwort verworfen (Fachregel)',
            self::Failed => 'KI nicht erreichbar, manuell bearbeiten',
            self::BudgetExceeded => 'KI pausiert (Budget), manuell bearbeiten',
            self::NotConfigured => 'KI nicht eingerichtet',
        };
    }

    public function producesSuggestions(): bool
    {
        return $this === self::Succeeded;
    }
}
