<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Core\Enums\ContactRoleType;
use App\Modules\Contacts\Mapping\VCardContactMapper;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactIdentifier;
use App\Modules\Contacts\Models\ContactRole;
use App\Modules\Contacts\Support\IdentifierNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;

/**
 * Schreibt gemappte vCard-Daten in den Kontaktspiegel: Upsert über (organization_id, source_system, external_id),
 * Prüfsumme und sync_version, contact_identifiers, Rollen aus CATEGORIES nur mit Mapping-Regel,
 * Mark-and-Sweep ausschließlich als Soft Delete.
 */
final class ContactMirrorService
{
    public const string RESULT_CREATED = 'created';

    public const string RESULT_UPDATED = 'updated';

    public const string RESULT_UNCHANGED = 'unchanged';

    public const string IDENTITY_UID_MISSING = 'uid_missing';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ContactDuplicateDetector $duplicates,
        private readonly WebhookDispatcherInterface $webhooks,
    ) {}

    /**
     * @param  array<string, mixed>  $local  Ausgabe von VCardContactMapper::toLocal()
     * @return array{contact: Contact, result: string}
     */
    public function upsert(int $organizationId, int $connectionId, array $local, ?string $etag): array
    {
        $externalId = (string) $local['external_id'];
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($organizationId, $connectionId, $local, $etag, $externalId, $now): array {
            $contact = Contact::query()
                ->withoutGlobalScope('organization')
                ->withTrashed()
                ->where('organization_id', $organizationId)
                ->where('source_system', Contact::SOURCE_IMMOWARE24)
                ->where('external_id_hash', hash('sha256', $externalId))
                ->first();

            $isNew = $contact === null;
            $contact ??= new Contact([
                'organization_id' => $organizationId,
                'source_system' => Contact::SOURCE_IMMOWARE24,
                'external_id' => $externalId,
                'first_synced_at' => $now,
            ]);

            $changed = $contact->applyChecksum(VCardContactMapper::checksumPayload($local));
            $restored = $contact->getAttribute('deleted_at') !== null;

            $contact->fill([
                'connection_id' => $connectionId,
                'kind' => $local['kind'],
                'salutation' => $local['salutation'],
                'first_name' => $local['first_name'],
                'last_name' => $local['last_name'],
                'company_name' => $local['company'],
                'job_title' => $local['job_title'],
                'emails' => $local['emails'],
                'phones' => $local['phones'],
                'addresses' => $local['addresses'],
                'notes' => $local['notes'],
                'categories' => $local['categories'],
                'vcard_uid' => $local['vcard_uid'],
                'vcard_href' => $local['href'],
                'vcard_rev' => $local['vcard_rev'],
                'vcard_extra' => $local['extra'] === [] ? null : $local['extra'],
                'remote_etag' => $etag,
                'identity_confidence' => ($local['uid_missing'] ?? false) ? self::IDENTITY_UID_MISSING : 'exact',
                'last_synced_at' => $now,
                'missing_since' => null,
                'deletion_reason' => null,
                'deleted_at' => null,
            ]);
            $contact->save();

            if ($isNew || $changed || $restored) {
                $this->rewriteIdentifiers($contact, $local);
                $this->applyRoles($contact, $organizationId, $connectionId, $local['categories'], $now);
                $this->duplicates->propose($contact);

                // Outbox in derselben Transaktion, nur IDs und Link, keine personenbezogenen Feldwerte.
                $this->webhooks->dispatch($isNew ? 'contact.created' : 'contact.updated', [
                    'id' => (int) $contact->getKey(),
                    'type' => 'contact',
                    'href' => '/api/v1/contacts/'.(int) $contact->getKey(),
                    'connection_id' => $connectionId,
                ], $organizationId);
            }

            return ['contact' => $contact, 'result' => $isNew ? self::RESULT_CREATED : (($changed || $restored) ? self::RESULT_UPDATED : self::RESULT_UNCHANGED)];
        });
    }

    /**
     * Soft Delete für Kontakte der Connection, deren href in der vollständigen Enumeration nicht mehr vorkommt.
     *
     * @param  array<string, true>  $seenHrefs  href => true
     * @return array{missing: int, deleted: int}
     */
    public function sweep(int $connectionId, array $seenHrefs, CollectionStateStore $states, string $collectionPath): array
    {
        $required = max(1, (int) $this->config->get('hub.contacts.sweep.required_misses', 1));
        $now = CarbonImmutable::now();
        $missing = 0;
        $deleted = 0;

        $rows = Contact::query()
            ->withoutGlobalScope('organization')
            ->where('connection_id', $connectionId)
            ->where('source_system', Contact::SOURCE_IMMOWARE24)
            ->whereNotNull('vcard_href')
            ->select(['id', 'vcard_href', 'missing_since'])
            ->lazyById(500);

        /** @var Contact $row */
        foreach ($rows as $row) {
            $href = (string) $row->vcard_href;

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

            Contact::query()->withoutGlobalScope('organization')->whereKey($row->getKey())->update($update);
        }

        return ['missing' => $missing, 'deleted' => $deleted];
    }

    /**
     * Ressource laut sync-collection gelöscht: nur missing_since setzen, Soft Delete erst nach Bestätigung
     * durch den nächsten vollständig enumerierenden Lauf (07-sync-strategy.md Abschnitt 4).
     */
    public function markMissingByHref(int $connectionId, string $href): void
    {
        Contact::query()
            ->withoutGlobalScope('organization')
            ->where('connection_id', $connectionId)
            ->where('vcard_href', $href)
            ->whereNull('missing_since')
            ->update(['missing_since' => CarbonImmutable::now()]);
    }

    /**
     * @param  array<string, mixed>  $local
     */
    private function rewriteIdentifiers(Contact $contact, array $local): void
    {
        ContactIdentifier::query()->where('contact_id', $contact->getKey())->delete();

        $rows = [];
        $seen = [];
        $now = CarbonImmutable::now();

        foreach ($local['emails'] as $index => $email) {
            $value = IdentifierNormalizer::email((string) $email['value']);
            if ($value === null || isset($seen['email'.$value])) {
                continue;
            }
            $seen['email'.$value] = true;
            $rows[] = ['contact_id' => $contact->getKey(), 'kind' => 'email', 'value_normalized' => $value, 'is_preferred' => $index === 0, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach ($local['phones'] as $index => $phone) {
            $value = IdentifierNormalizer::phone((string) $phone['value']);
            if ($value === null || isset($seen['phone'.$value])) {
                continue;
            }
            $seen['phone'.$value] = true;
            $rows[] = ['contact_id' => $contact->getKey(), 'kind' => 'phone', 'value_normalized' => $value, 'is_preferred' => $index === 0, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($rows !== []) {
            ContactIdentifier::query()->insert($rows);
        }
    }

    /**
     * Rollen aus CATEGORIES: nur Kategorien mit Regel in hub.contacts.category_roles erzeugen contact_roles.
     * Ohne Regel bleibt contact_roles unverändert. Eine Person mit mehreren Rollen bleibt ein Kontakt.
     *
     * @param  array<int, string>  $categories
     */
    private function applyRoles(Contact $contact, int $organizationId, int $connectionId, array $categories, CarbonImmutable $now): void
    {
        /** @var array<string, string> $rules */
        $rules = (array) $this->config->get('hub.contacts.category_roles', []);

        if ($rules === []) {
            return;
        }

        $normalizedRules = [];
        foreach ($rules as $category => $role) {
            $normalizedRules[mb_strtolower(trim((string) $category))] = (string) $role;
        }

        $mappedRoles = [];
        foreach ($categories as $category) {
            $role = $normalizedRules[mb_strtolower(trim($category))] ?? null;
            if ($role !== null && ContactRoleType::tryFrom($role) !== null) {
                $mappedRoles[$role] = true;
            }
        }

        foreach (array_keys($mappedRoles) as $role) {
            $externalId = $contact->getAttribute('external_id').'#role:'.$role;
            $existing = ContactRole::query()
                ->withoutGlobalScope('organization')
                ->withTrashed()
                ->where('organization_id', $organizationId)
                ->where('source_system', Contact::SOURCE_IMMOWARE24)
                ->where('external_id_hash', hash('sha256', $externalId))
                ->first();

            $existing ??= new ContactRole([
                'organization_id' => $organizationId,
                'source_system' => Contact::SOURCE_IMMOWARE24,
                'external_id' => $externalId,
                'first_synced_at' => $now,
            ]);

            $existing->applyChecksum(['contact' => $contact->getAttribute('external_id'), 'role' => $role]);
            $existing->fill([
                'contact_id' => $contact->getKey(),
                'connection_id' => $connectionId,
                'role' => $role,
                'external_parent_id' => $contact->getAttribute('external_id'),
                'last_synced_at' => $now,
                'deleted_at' => null,
            ]);
            $existing->save();
        }

        // Rollen mit Regel, die in der vCard nicht mehr vorkommen, werden weich gelöscht; fremde Rollen bleiben unberührt.
        $managed = array_values($normalizedRules);
        ContactRole::query()
            ->withoutGlobalScope('organization')
            ->where('contact_id', $contact->getKey())
            ->where('source_system', Contact::SOURCE_IMMOWARE24)
            ->whereIn('role', $managed)
            ->whereNotIn('role', array_keys($mappedRoles) === [] ? [''] : array_keys($mappedRoles))
            ->where('external_id', 'like', '%#role:%')
            ->update(['deleted_at' => $now, 'deletion_reason' => 'category_removed']);
    }
}
