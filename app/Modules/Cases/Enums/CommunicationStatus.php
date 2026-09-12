<?php

declare(strict_types=1);

namespace App\Modules\Cases\Enums;

/**
 * Kommunikationsstatus eines Vorgangs (Dimension 2). Beantwortet ist nicht erledigt.
 */
enum CommunicationStatus: string
{
    case ReplyNeeded = 'reply_needed';
    case Acknowledged = 'acknowledged';
    case UpdateRequired = 'update_required';
    case DraftExists = 'draft_exists';
    case InReview = 'in_review';
    case Sent = 'sent';
    case NoReplyNeeded = 'no_reply_needed';

    public function label(): string
    {
        return match ($this) {
            self::ReplyNeeded => 'Antwort erforderlich',
            self::Acknowledged => 'Eingang bestätigt',
            self::UpdateRequired => 'Zwischenstand fällig',
            self::DraftExists => 'Entwurf vorhanden',
            self::InReview => 'In Prüfung',
            self::Sent => 'Gesendet (verifiziert)',
            self::NoReplyNeeded => 'Keine Antwort nötig',
        };
    }

    public function requiresAction(): bool
    {
        return in_array($this, [self::ReplyNeeded, self::UpdateRequired, self::DraftExists, self::InReview], true);
    }
}
