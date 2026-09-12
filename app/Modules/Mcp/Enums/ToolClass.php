<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Enums;

/**
 * Klassifikation eines Tools gemäß 08-security.md Abschnitt 8.
 * read: lesend ohne Wirkung. write: Hub-eigene Zustandsänderung (Vorgang, Änderungsvorschlag), nie Immoware24.
 * never_autonomous: nur durch einen Menschen, im Katalog gelistet, ohne Implementierung.
 */
enum ToolClass: string
{
    case Read = 'read';
    case Write = 'write';
    case NeverAutonomous = 'never_autonomous';
}
