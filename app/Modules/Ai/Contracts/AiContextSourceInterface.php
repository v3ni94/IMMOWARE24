<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Security\Models\User;

/**
 * Liefert dem KI-Adapter zusätzliche Dokumentauszüge zu einem Vorgang, aber nur solche, für die die handelnde Person
 * berechtigt ist (Anwendungsrecht und Quellrecht). Implementierung im Modul Drive (DriveAiContextSource). Ohne
 * Bindung oder ohne Berechtigung liefert die Quelle eine leere Liste; die KI arbeitet dann nur mit der Nachricht.
 * Offen: gehört mittelfristig nach app/Core/Contracts/Mail, wird hier modul-lokal angelegt.
 */
interface AiContextSourceInterface
{
    /**
     * @return array<int, array{label: string, content: string, source: string}>
     */
    public function excerptsFor(MailCase $case, User $user, int $maxExcerpts, int $maxChars): array;
}
