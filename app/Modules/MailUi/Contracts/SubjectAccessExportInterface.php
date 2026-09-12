<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Contracts;

use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;

/**
 * Dünne Schnittstelle zum Auskunftsexport (DSGVO Art. 15). Die Oberfläche fordert den Export an; die Erstellung läuft
 * asynchron im Fachmodul, nur mit Recht mail.export, mit maskierten Bankdaten und Auditeintrag.
 */
interface SubjectAccessExportInterface
{
    public const string FORMAT_JSON = 'json';

    public const string FORMAT_CSV = 'csv';

    /**
     * Fordert den Export an. $sync erstellt ihn sofort im Prozess (Befehl, Tests), sonst Queue low.
     */
    public function request(int $contactId, User $actor, string $format = self::FORMAT_JSON, bool $sync = false): WorkflowResult;
}
