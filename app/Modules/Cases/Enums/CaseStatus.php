<?php

declare(strict_types=1);

namespace App\Modules\Cases\Enums;

/**
 * Bearbeitungsstatus eines Vorgangs (Dimension 1, docs/mail/04-status-und-sla.md).
 * Gelesen ist nicht bearbeitet: der Status wechselt nur durch eine Handlung eines Verantwortlichen.
 */
enum CaseStatus: string
{
    case New = 'new';
    case AssignmentOpen = 'assignment_open';
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingCustomer = 'waiting_customer';
    case WaitingExternal = 'waiting_external';
    case WaitingApproval = 'waiting_approval';
    case Blocked = 'blocked';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Neu',
            self::AssignmentOpen => 'Zuordnung offen',
            self::Open => 'Offen',
            self::InProgress => 'In Bearbeitung',
            self::WaitingCustomer => 'Wartet auf Kunde',
            self::WaitingExternal => 'Wartet auf Dritte',
            self::WaitingApproval => 'Wartet auf Freigabe',
            self::Blocked => 'Blockiert',
            self::Resolved => 'Gelöst',
            self::Closed => 'Geschlossen',
            self::Reopened => 'Wiedereröffnet',
        };
    }

    /**
     * Offene Vorgänge brauchen Verantwortlichen, nächsten Schritt und Fälligkeit.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }

    /**
     * Uhren pausieren, während auf Externe gewartet wird (04, Abschnitt 4).
     */
    public function pausesClocks(): bool
    {
        return in_array($this, [self::WaitingCustomer, self::WaitingExternal], true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::AssignmentOpen, self::Open, self::Closed],
            self::AssignmentOpen => [self::Open, self::Closed],
            self::Open => [self::InProgress, self::WaitingCustomer, self::WaitingExternal, self::WaitingApproval, self::Blocked, self::Resolved, self::Closed],
            self::InProgress => [self::WaitingCustomer, self::WaitingExternal, self::WaitingApproval, self::Blocked, self::Resolved, self::Open],
            self::WaitingCustomer, self::WaitingExternal, self::WaitingApproval, self::Blocked => [self::InProgress, self::Open, self::Resolved],
            self::Resolved => [self::Closed, self::Reopened],
            self::Closed => [self::Reopened],
            self::Reopened => [self::InProgress, self::Open, self::Resolved],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
