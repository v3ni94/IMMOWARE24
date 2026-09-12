<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactIdentifier;
use App\Modules\Contacts\Models\ContactMerge;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Findet Duplikatkandidaten (gleiche normalisierte E-Mail oder Telefonnummer, unterschiedliche externe ID)
 * innerhalb eines Mandanten und legt sie als Vorschlag in contact_merges ab. Es wird nie automatisch gemergt.
 */
final class ContactDuplicateDetector
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * @return int Anzahl neu angelegter Vorschläge
     */
    public function propose(Contact $contact): int
    {
        if (! (bool) $this->config->get('hub.contacts.duplicates.enabled', true)) {
            return 0;
        }

        $kinds = array_keys(array_filter([
            ContactMerge::MATCH_EMAIL => (bool) $this->config->get('hub.contacts.duplicates.by_email', true),
            ContactMerge::MATCH_PHONE => (bool) $this->config->get('hub.contacts.duplicates.by_phone', true),
        ]));

        if ($kinds === []) {
            return 0;
        }

        $identifiers = ContactIdentifier::query()
            ->where('contact_id', $contact->getKey())
            ->whereIn('kind', $kinds)
            ->get(['kind', 'value_normalized']);

        if ($identifiers->isEmpty()) {
            return 0;
        }

        $created = 0;

        foreach ($identifiers as $identifier) {
            $candidates = ContactIdentifier::query()
                ->select(['contact_identifiers.contact_id'])
                ->join('contacts', 'contacts.id', '=', 'contact_identifiers.contact_id')
                ->where('contact_identifiers.kind', $identifier->kind)
                ->where('contact_identifiers.value_normalized', $identifier->value_normalized)
                ->where('contact_identifiers.contact_id', '!=', $contact->getKey())
                ->where('contacts.organization_id', $contact->getAttribute('organization_id'))
                ->where('contacts.external_id_hash', '!=', $contact->getAttribute('external_id_hash'))
                ->whereNull('contacts.deleted_at')
                ->whereNull('contacts.merged_into_id')
                ->distinct()
                ->limit(20)
                ->pluck('contact_identifiers.contact_id');

            foreach ($candidates as $candidateId) {
                if ($this->createProposal((int) $contact->getKey(), (int) $candidateId, (string) $identifier->kind)) {
                    $created++;
                }
            }
        }

        return $created;
    }

    private function createProposal(int $a, int $b, string $matchKind): bool
    {
        $source = max($a, $b);
        $target = min($a, $b);

        $exists = ContactMerge::query()
            ->where(function ($query) use ($source, $target): void {
                $query->where(['source_contact_id' => $source, 'target_contact_id' => $target])
                    ->orWhere(['source_contact_id' => $target, 'target_contact_id' => $source]);
            })
            ->exists();

        if ($exists) {
            return false;
        }

        ContactMerge::query()->create([
            'source_contact_id' => $source,
            'target_contact_id' => $target,
            'status' => ContactMerge::STATUS_PROPOSED,
            'match_kind' => $matchKind,
            'proposed_at' => CarbonImmutable::now(),
            'reason' => sprintf('Duplikatkandidat aus CardDAV-Abgleich: gleiche %s bei unterschiedlicher UID.', $matchKind === ContactMerge::MATCH_EMAIL ? 'E-Mail-Adresse' : 'Telefonnummer'),
        ]);

        return true;
    }
}
