<?php

declare(strict_types=1);

namespace App\Modules\Actions\Enums;

/**
 * Status eines Aktionsplans (Dimension 3, fachliches Ergebnis). Ein erfolgreicher HTTP-Aufruf ist "executed",
 * nie "verified": verifiziert wird erst durch Nachlesen im Zielsystem oder manuelle Bestätigung.
 */
enum ActionStatus: string
{
    case Proposed = 'proposed';
    case Validated = 'validated';
    case ApprovalRequired = 'approval_required';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Executing = 'executing';
    case Executed = 'executed';
    case Verified = 'verified';
    case Failed = 'failed';
    case ResultUnclear = 'result_unclear';
    case ManualReview = 'manual_review';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Vorgeschlagen',
            self::Validated => 'Geprüft',
            self::ApprovalRequired => 'Freigabe erforderlich',
            self::Approved => 'Freigegeben',
            self::Scheduled => 'Eingeplant',
            self::Executing => 'Wird ausgeführt',
            self::Executed => 'Ausgeführt (unverifiziert)',
            self::Verified => 'Verifiziert',
            self::Failed => 'Fehlgeschlagen',
            self::ResultUnclear => 'Ergebnis unklar',
            self::ManualReview => 'Manuelle Prüfung',
        };
    }

    /**
     * Nur dieser Status gilt als abgeschlossenes Geschäftsergebnis.
     */
    public function isBusinessComplete(): bool
    {
        return $this === self::Verified;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Verified, self::Failed], true);
    }

    public function needsHumanAttention(): bool
    {
        return in_array($this, [self::ApprovalRequired, self::Failed, self::ResultUnclear, self::ManualReview], true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Proposed => [self::Validated, self::Failed, self::ManualReview],
            self::Validated => [self::ApprovalRequired, self::Scheduled, self::ManualReview],
            self::ApprovalRequired => [self::Approved, self::Failed, self::ManualReview],
            self::Approved => [self::Scheduled, self::Executing],
            self::Scheduled => [self::Executing, self::Failed],
            self::Executing => [self::Executed, self::Failed, self::ResultUnclear],
            self::Executed => [self::Verified, self::ResultUnclear, self::Failed],
            self::ResultUnclear => [self::Verified, self::Failed, self::ManualReview],
            self::ManualReview => [self::Verified, self::Failed, self::Proposed],
            self::Verified, self::Failed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
