<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\ICal\ICalendarParser;
use App\Modules\Calendar\Mapping\ICalEventMapper;
use App\Modules\Contacts\Contracts\DavMirrorHandlerInterface;
use App\Modules\Contacts\Dav\DavResource;
use App\Modules\Contacts\Services\CollectionStateStore;

final class CalendarMirrorHandler implements DavMirrorHandlerInterface
{
    public function __construct(
        private readonly int $organizationId,
        private readonly int $connectionId,
        private readonly string $collectionPath,
        private readonly ICalendarParser $parser,
        private readonly ICalEventMapper $mapper,
        private readonly CalendarMirrorService $mirror,
        private readonly CollectionStateStore $states,
    ) {}

    public function handleResource(DavResource $resource): array
    {
        $events = $this->parser->parseAll((string) $resource->data);

        if ($events === []) {
            throw new \RuntimeException('Ressource enthält kein VEVENT.');
        }

        $created = 0;
        $updated = 0;
        $externalIds = [];

        foreach ($events as $event) {
            $local = $this->mapper->toLocal(['event' => $event, 'href' => $resource->href, 'etag' => $resource->etag]);
            $outcome = $this->mirror->upsert($this->organizationId, $this->connectionId, $local, $resource->etag, $this->collectionPath);
            $externalIds[] = (string) $local['external_id'];

            if ($outcome['result'] === CalendarMirrorService::RESULT_CREATED) {
                $created++;
            } elseif ($outcome['result'] === CalendarMirrorService::RESULT_UPDATED) {
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'external_ids' => $externalIds];
    }

    public function handleRemoved(string $href): void
    {
        $this->mirror->markMissingByHref($this->connectionId, $href);
    }

    public function sweep(array $seenHrefs): array
    {
        return $this->mirror->sweep($this->connectionId, $seenHrefs, $this->states, $this->collectionPath);
    }
}
