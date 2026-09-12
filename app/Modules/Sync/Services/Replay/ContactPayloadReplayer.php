<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services\Replay;

use App\Modules\Contacts\Mapping\VCardContactMapper;
use App\Modules\Contacts\Services\ContactMirrorService;
use App\Modules\Contacts\VCard\VCardParser;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Models\ExternalPayload;
use RuntimeException;

/**
 * Replay archivierter vCards (payload_type vcard) über VCardParser, VCardContactMapper und ContactMirrorService.
 * import_metadata: href, etag, organization_id (Pflicht für den Mandantenbezug).
 */
final class ContactPayloadReplayer implements PayloadReplayerInterface
{
    public const string PAYLOAD_TYPE = 'vcard';

    public function __construct(
        private readonly VCardParser $parser,
        private readonly VCardContactMapper $mapper,
        private readonly ContactMirrorService $mirror,
    ) {}

    public function entityType(): string
    {
        return SyncEntity::Contact->value;
    }

    public function payloadType(): string
    {
        return self::PAYLOAD_TYPE;
    }

    public function replay(ExternalPayload $payload, string $content): ReplayOutcome
    {
        $cards = $this->parser->parseAll($content);

        if ($cards === []) {
            throw new RuntimeException('Nutzlast enthält keine vCard.');
        }

        $metadata = (array) ($payload->getAttribute('import_metadata') ?? []);
        $organizationId = PayloadMetadata::organizationId($payload, $metadata);
        $href = (string) ($metadata['href'] ?? '');
        $etag = $payload->getAttribute('remote_etag') ?? ($metadata['etag'] ?? null);
        $etag = is_string($etag) && $etag !== '' ? $etag : null;

        $outcome = new ReplayOutcome;

        foreach ($cards as $card) {
            $local = $this->mapper->toLocal(['vcard' => $card, 'href' => $href, 'etag' => $etag]);
            $result = $this->mirror->upsert($organizationId, (int) $payload->getAttribute('connection_id'), $local, $etag);

            $outcome = $outcome->merge(new ReplayOutcome(
                created: $result['result'] === ContactMirrorService::RESULT_CREATED ? 1 : 0,
                updated: $result['result'] === ContactMirrorService::RESULT_UPDATED ? 1 : 0,
                unchanged: $result['result'] === ContactMirrorService::RESULT_UNCHANGED ? 1 : 0,
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
