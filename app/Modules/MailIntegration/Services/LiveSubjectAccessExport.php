<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Services;

use App\Core\Enums\AuditSource;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\MailIntegration\Jobs\SubjectAccessExportJob;
use App\Modules\MailUi\Contracts\SubjectAccessExportInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\DTO\AuditActor;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;

/**
 * Anforderung eines Auskunftsexports: Recht mail.export (Systemrolle und Team-Rolle), Kontakt derselben Organisation,
 * Auditeintrag mit Kennung, danach Job auf Queue low. Wird von der Oberfläche (Admin-Aktion) und vom Befehl
 * mail:subject-access-export genutzt.
 */
final class LiveSubjectAccessExport implements SubjectAccessExportInterface
{
    public function __construct(
        private readonly MailAccess $access,
        private readonly Dispatcher $bus,
        private readonly AuditLogger $audit,
    ) {}

    public function request(int $contactId, User $actor, string $format = self::FORMAT_JSON, bool $sync = false): WorkflowResult
    {
        if (! in_array($format, [self::FORMAT_JSON, self::FORMAT_CSV], true)) {
            return WorkflowResult::failed('Unbekanntes Exportformat.');
        }

        if (! $this->access->can($actor, 'mail.export')) {
            return WorkflowResult::failed('Für den Auskunftsexport fehlt das Recht mail.export.');
        }

        $contact = Contact::query()->withoutGlobalScopes()
            ->where('organization_id', $actor->getAttribute('organization_id'))
            ->find($contactId);

        if (! $contact instanceof Contact) {
            return WorkflowResult::failed('Kontakt in dieser Organisation nicht gefunden.');
        }

        $exportUuid = (string) Str::uuid();

        $this->audit->record(
            action: 'mail.export.subject_access_requested',
            entity: $contact,
            after: ['contact_id' => $contactId, 'export_uuid' => $exportUuid, 'format' => $format],
            source: AuditSource::Mail,
            actor: AuditActor::user((int) $actor->getKey(), (int) $actor->getAttribute('organization_id')),
        );

        $job = new SubjectAccessExportJob($contactId, $exportUuid, $format, (int) $actor->getKey());

        if ($sync) {
            $this->bus->dispatchSync($job);

            return WorkflowResult::ok('Auskunftsexport '.$exportUuid.' erstellt. Ablage und Anzahl stehen im Auditlog.', (int) $contact->getKey());
        }

        $this->bus->dispatch($job);

        return WorkflowResult::ok('Auskunftsexport '.$exportUuid.' angefordert. Die Erstellung läuft im Hintergrund; das Ergebnis erscheint nach Fertigstellung im Auditlog und auf der Speicherdisk.', (int) $contact->getKey());
    }
}
