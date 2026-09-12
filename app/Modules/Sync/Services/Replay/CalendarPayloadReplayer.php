<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services\Replay;

use App\Modules\Calendar\ICal\ICalendarParser;
use App\Modules\Calendar\Mapping\ICalEventMapper;
use App\Modules\Calendar\Services\CalendarMirrorService;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Models\ExternalPayload;
use RuntimeException;

/**
 * Replay archivierter iCalendar-Ressourcen (payload_type ical) über ICalendarParser, ICalEventMapper und
 * CalendarMirrorService. import_metadata: href, etag, collection_path, organization_id.
 */
final class CalendarPayloadReplayer implements PayloadReplayerInterface
{
    public const string PAYLOAD_TYPE = 'ical';

    public function __construct(
        private readonly ICalendarParser $parser,
        private readonly ICalEventMapper $mapper,
        private readonly CalendarMirrorService $mirror,
    ) {}

    public function entityType(): string
    {
        return SyncEntity::CalendarEvent->value;
    }

    public function payloadType(): string
    {
        return self::PAYLOAD_TYPE;
    }

    public function replay(ExternalPayload $payload, string $content): ReplayOutcome
    {
        $events = $this->parser->parseAll($content);

        if ($events === []) {
            throw new RuntimeException('Nutzlast enthält kein VEVENT.');
        }

        $metadata = (array) ($payload->getAttribute('import_metadata') ?? []);
        $organizationId = PayloadMetadata::organizationId($payload, $metadata);
        $href = (string) ($metadata['href'] ?? '');
        $collectionPath = (string) ($metadata['collection_path'] ?? '');
        $etag = $payload->getAttribute('remote_etag') ?? ($metadata['etag'] ?? null);
        $etag = is_string($etag) && $etag !== '' ? $etag : null;

        if ($collectionPath === '') {
            throw new RuntimeException('Nutzlast ohne collection_path in import_metadata, Replay nicht möglich.');
        }

        $outcome = new ReplayOutcome;

        foreach ($events as $event) {
            $local = $this->mapper->toLocal(['event' => $event, 'href' => $href, 'etag' => $etag]);
            $result = $this->mirror->upsert($organizationId, (int) $payload->getAttribute('connection_id'), $local, $etag, $collectionPath);

            $outcome = $outcome->merge(new ReplayOutcome(
                created: $result['result'] === CalendarMirrorService::RESULT_CREATED ? 1 : 0,
                updated: $result['result'] === CalendarMirrorService::RESULT_UPDATED ? 1 : 0,
                unchanged: $result['result'] === CalendarMirrorService::RESULT_UNCHANGED ? 1 : 0,
                externalIds: [(string) $local['external_id']],
            ));
        }

        return $outcome;
    }

    public function finish(): void
    {
        // Kontakt- und Kalenderspiegel schreiben keine sync_events, kein Lauf zu schließen.
    }
}
