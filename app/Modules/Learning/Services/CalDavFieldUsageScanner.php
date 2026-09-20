<?php

declare(strict_types=1);

namespace App\Modules\Learning\Services;

use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Services\DavClientFactory;
use Throwable;

/**
 * Lernphase Immoware24, Art caldav: prüft lesend die Erreichbarkeit und Fähigkeiten der Kalender-Collection
 * (PROPFIND Depth 0, wie ProbeService) und wertet die Feldnutzung der bereits gespiegelten Termine aus
 * (calendar_events). Kein zusätzlicher Abruf einzelner Termine: die Auswertung nutzt ausschließlich Daten, die
 * der Hub über den laufenden Abgleich bereits legitim hält. DavClientFactory ist der öffentliche Dienst des
 * Moduls Contacts, der auch den CalDAV-Client baut (siehe CalDavConnector).
 */
final class CalDavFieldUsageScanner
{
    public function __construct(
        private readonly DavClientFactory $clients,
        private readonly int $organizationId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(ImmowareConnection $connection): array
    {
        $collection = $this->collectionFacts($connection);
        $total = CalendarEvent::query()->forOrganization($this->organizationId)->count();

        $scalarFields = ['description', 'location', 'timezone', 'status', 'recurrence_rule', 'property_id', 'unit_id', 'contact_id'];

        $usage = [];

        foreach ($scalarFields as $field) {
            $usage[$field] = $total > 0 ? CalendarEvent::query()->forOrganization($this->organizationId)->whereNotNull($field)->count() : 0;
        }

        $usage['attendees'] = $total > 0
            ? CalendarEvent::query()->forOrganization($this->organizationId)->whereNotNull('attendees')->where('attendees', '!=', '[]')->count()
            : 0;
        $usage['all_day'] = $total > 0 ? CalendarEvent::query()->forOrganization($this->organizationId)->where('all_day', true)->count() : 0;

        return [
            'kind' => 'caldav',
            'collection' => $collection,
            'mirrored_events' => $total,
            'field_usage' => $usage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionFacts(ImmowareConnection $connection): array
    {
        try {
            $info = $this->clients->caldav($connection)->propfindCollection();

            return [
                'reachable' => true,
                'ctag_present' => $info->ctag !== null,
                'supports_sync_collection' => $info->supportsSyncCollection(),
                'supported_reports' => $info->supportedReports,
            ];
        } catch (Throwable $e) {
            return ['reachable' => false, 'error' => $e->getMessage()];
        }
    }
}
