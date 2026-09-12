<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Mapping\ICalEventMapper;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Contacts\Services\CollectionStateStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Spiegel für calendar_events: Upsert über externe ID, Prüfsumme, Mark-and-Sweep als Soft Delete.
 */
final class CalendarMirrorService
{
    public const string RESULT_CREATED = 'created';

    public const string RESULT_UPDATED = 'updated';

    public const string RESULT_UNCHANGED = 'unchanged';

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * @param  array<string, mixed>  $local
     * @return array{event: CalendarEvent, result: string}
     */
    public function upsert(int $organizationId, int $connectionId, array $local, ?string $etag, string $collectionPath): array
    {
        $externalId = (string) $local['external_id'];
        $now = CarbonImmutable::now();

        $event = CalendarEvent::query()
            ->withoutGlobalScope('organization')
            ->withTrashed()
            ->where('organization_id', $organizationId)
            ->where('source_system', CalendarEvent::SOURCE_IMMOWARE24)
            ->where('external_id_hash', hash('sha256', $externalId))
            ->first();

        $isNew = $event === null;
        $event ??= new CalendarEvent([
            'organization_id' => $organizationId,
            'source_system' => CalendarEvent::SOURCE_IMMOWARE24,
            'external_id' => $externalId,
            'first_synced_at' => $now,
        ]);

        $changed = $event->applyChecksum(ICalEventMapper::checksumPayload($local));
        $restored = $event->getAttribute('deleted_at') !== null;

        $event->fill([
            'connection_id' => $connectionId,
            'ical_uid' => $local['uid'],
            'ical_href' => $local['href'],
            'remote_etag' => $etag,
            'calendar_path_hash' => CollectionStateStore::pathHash($collectionPath),
            'summary' => $local['summary'] !== null ? mb_substr((string) $local['summary'], 0, 500) : null,
            'description' => $local['description'],
            'location' => $local['location'] !== null ? mb_substr((string) $local['location'], 0, 500) : null,
            'starts_at' => $local['starts_at'],
            'ends_at' => $local['ends_at'],
            'all_day' => (bool) $local['all_day'],
            'timezone' => $local['timezone'],
            'status' => $local['status'],
            'recurrence_rule' => $local['rrule'] !== null ? mb_substr((string) $local['rrule'], 0, 500) : null,
            'sequence' => $local['sequence'] !== null ? (string) $local['sequence'] : null,
            'attendees' => $local['attendees'],
            'external_updated_at' => $local['last_modified'],
            'identity_confidence' => ($local['uid_missing'] ?? false) ? 'uid_missing' : 'exact',
            'last_synced_at' => $now,
            'missing_since' => null,
            'deletion_reason' => null,
            'deleted_at' => null,
        ]);
        $event->save();

        return ['event' => $event, 'result' => $isNew ? self::RESULT_CREATED : (($changed || $restored) ? self::RESULT_UPDATED : self::RESULT_UNCHANGED)];
    }

    /**
     * @param  array<string, true>  $seenHrefs
     * @return array{missing: int, deleted: int}
     */
    public function sweep(int $connectionId, array $seenHrefs, CollectionStateStore $states, string $collectionPath): array
    {
        $required = max(1, (int) $this->config->get('hub.calendar.sweep.required_misses', 1));
        $now = CarbonImmutable::now();
        $missing = 0;
        $deleted = 0;

        $rows = CalendarEvent::query()
            ->withoutGlobalScope('organization')
            ->where('connection_id', $connectionId)
            ->where('source_system', CalendarEvent::SOURCE_IMMOWARE24)
            ->whereNotNull('ical_href')
            ->select(['id', 'ical_href', 'missing_since'])
            ->lazyById(500);

        /** @var CalendarEvent $row */
        foreach ($rows as $row) {
            $href = (string) $row->ical_href;

            if (isset($seenHrefs[$href])) {
                continue;
            }

            $misses = $states->markMissing($connectionId, $collectionPath, $href);
            $missing++;
            $update = ['missing_since' => $row->missing_since ?? $now];

            if ($misses >= $required) {
                $update['deleted_at'] = $now;
                $update['deletion_reason'] = 'missing_remote';
                $deleted++;
            }

            CalendarEvent::query()->withoutGlobalScope('organization')->whereKey($row->getKey())->update($update);
        }

        return ['missing' => $missing, 'deleted' => $deleted];
    }

    public function markMissingByHref(int $connectionId, string $href): void
    {
        CalendarEvent::query()
            ->withoutGlobalScope('organization')
            ->where('connection_id', $connectionId)
            ->where('ical_href', $href)
            ->whereNull('missing_since')
            ->update(['missing_since' => CarbonImmutable::now()]);
    }
}
