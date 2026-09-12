<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Jobs;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Modules\Contacts\Models\Contact;
use App\Modules\MailIntegration\Services\SubjectAccessExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Erstellt den Auskunftsexport (DSGVO Art. 15) asynchron auf der Queue low. Erfolg und Fehlschlag werden auditiert
 * (Kontakt, Kennung, Anzahl, Ablagepfad; nie Inhalte). Ein Retry überschreibt dieselbe Kennung, es entsteht kein
 * zweiter Export.
 */
final class SubjectAccessExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly int $contactId,
        public readonly string $exportUuid,
        public readonly string $format,
        public readonly ?int $requestedByUserId,
    ) {
        $this->onQueue((string) config('hub.mail.subject_access_export.queue', 'low'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(SubjectAccessExportService $exports, AuditLoggerInterface $audit): void
    {
        $contact = Contact::query()->withoutGlobalScopes()->find($this->contactId);

        if (! $contact instanceof Contact) {
            $audit->log('mail.export.subject_access_failed', null, [], ['contact_id' => $this->contactId, 'export_uuid' => $this->exportUuid, 'reason' => 'contact_missing'], AuditSource::System->value);

            return;
        }

        $manifest = $exports->write($contact, $this->exportUuid, $this->format);

        $audit->log('mail.export.subject_access_completed', $contact, [], [
            'contact_id' => $this->contactId,
            'export_uuid' => $this->exportUuid,
            'format' => $this->format,
            'requested_by_user_id' => $this->requestedByUserId,
            'path' => $manifest['path'],
            'counts' => $manifest['counts'],
        ], AuditSource::System->value);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Auskunftsexport fehlgeschlagen.', ['contact_id' => $this->contactId, 'export_uuid' => $this->exportUuid, 'error' => $exception::class]);

        try {
            app(AuditLoggerInterface::class)->log('mail.export.subject_access_failed', null, [], ['contact_id' => $this->contactId, 'export_uuid' => $this->exportUuid, 'reason' => $exception::class], AuditSource::System->value);
        } catch (Throwable) {
            // Auditfehler darf den Fehlerpfad nicht erneut werfen.
        }
    }
}
