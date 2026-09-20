<?php

declare(strict_types=1);

namespace App\Modules\Learning\Services;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Services\DavClientFactory;
use Throwable;

/**
 * Lernphase Immoware24, Art carddav: prüft lesend die Erreichbarkeit und Fähigkeiten der Adressbuch-Collection
 * (PROPFIND Depth 0, wie ProbeService) und wertet die Feldnutzung der bereits gespiegelten Kontakte aus
 * (contacts). Kein zusätzlicher Abruf einzelner vCards: die Auswertung nutzt ausschließlich Daten, die der
 * Hub über den laufenden Abgleich bereits legitim hält.
 */
final class CardDavFieldUsageScanner
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
        $total = Contact::query()->forOrganization($this->organizationId)->count();

        $scalarFields = ['salutation', 'first_name', 'last_name', 'company_id', 'birth_date', 'vcard_uid'];
        $arrayFields = ['emails', 'phones', 'addresses'];

        $usage = [];

        foreach ($scalarFields as $field) {
            $usage[$field] = $total > 0 ? Contact::query()->forOrganization($this->organizationId)->whereNotNull($field)->count() : 0;
        }

        foreach ($arrayFields as $field) {
            $usage[$field] = $total > 0
                ? Contact::query()->forOrganization($this->organizationId)->whereNotNull($field)->where($field, '!=', '[]')->count()
                : 0;
        }

        return [
            'kind' => 'carddav',
            'collection' => $collection,
            'mirrored_contacts' => $total,
            'field_usage' => $usage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionFacts(ImmowareConnection $connection): array
    {
        try {
            $info = $this->clients->carddav($connection)->propfindCollection();

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
