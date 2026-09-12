<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Modules\Connector\Support\DavResponse;
use App\Modules\Contacts\Contracts\DavMirrorHandlerInterface;
use App\Modules\Contacts\Mapping\VCardContactMapper;
use App\Modules\Contacts\VCard\VCardParser;

/**
 * Verarbeitet vCard-Ressourcen eines Pull-Laufs für genau eine Connection.
 */
final class ContactMirrorHandler implements DavMirrorHandlerInterface
{
    public function __construct(
        private readonly int $organizationId,
        private readonly int $connectionId,
        private readonly string $collectionPath,
        private readonly VCardParser $parser,
        private readonly VCardContactMapper $mapper,
        private readonly ContactMirrorService $mirror,
        private readonly CollectionStateStore $states,
    ) {}

    public function handleResource(DavResponse $resource): array
    {
        $cards = $this->parser->parseAll((string) $resource->data);

        if ($cards === []) {
            throw new \RuntimeException('Ressource enthält keine vCard.');
        }

        $created = 0;
        $updated = 0;
        $externalIds = [];

        foreach ($cards as $card) {
            $local = $this->mapper->toLocal(['vcard' => $card, 'href' => $resource->href, 'etag' => $resource->etag]);
            $outcome = $this->mirror->upsert($this->organizationId, $this->connectionId, $local, $resource->etag);
            $externalIds[] = (string) $local['external_id'];

            if ($outcome['result'] === ContactMirrorService::RESULT_CREATED) {
                $created++;
            } elseif ($outcome['result'] === ContactMirrorService::RESULT_UPDATED) {
                $updated++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'external_ids' => $externalIds];
    }

    public function handleRemoved(string $href): void
    {
        $this->mirror->markMissingByHref($this->connectionId, $href);
    }

    public function sweep(array $seenHrefs, bool $healthOk = true): array
    {
        return $this->mirror->sweep($this->connectionId, $seenHrefs, $this->states, $this->collectionPath, $healthOk);
    }
}
