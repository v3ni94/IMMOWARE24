<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Modules\MailUi\Contracts\SubjectAccessExportInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;

/**
 * Null-Implementierung bis zur Verdrahtung durch das Modul MailIntegration: meldet den Dienst als nicht verfügbar.
 */
final class NullSubjectAccessExport implements SubjectAccessExportInterface
{
    public function request(int $contactId, User $actor, string $format = self::FORMAT_JSON, bool $sync = false): WorkflowResult
    {
        return WorkflowResult::unavailable('Der Auskunftsexport ist nicht verdrahtet.');
    }
}
