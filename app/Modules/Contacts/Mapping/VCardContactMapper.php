<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Mapping;

use App\Core\Contracts\FieldMapperInterface;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Contacts\Support\IdentifierNormalizer;
use App\Modules\Contacts\VCard\VCard;

/**
 * FieldMapping vCard nach contacts, Version 1.0.
 * Eingabe (toLocal): ['vcard' => VCard|array, 'href' => string, 'etag' => ?string].
 * Ausgabe: normalisierte Hub-Attribute inkl. external_id (UID, Fallback href mit uid_missing = true).
 * Zuordnung ausschließlich über UID bzw. href, nie über Namen oder E-Mail.
 */
final class VCardContactMapper implements FieldMapperInterface
{
    public const string SOURCE_FORMAT = 'vcard';

    public const int VERSION = 1;

    public function entityType(): string
    {
        return 'contact';
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /**
     * @param  array<string, mixed>  $external
     * @return array<string, mixed>
     */
    public function toLocal(array $external): array
    {
        $card = $this->card($external);
        $href = (string) ($external['href'] ?? '');

        $emails = [];
        foreach ($card->emails as $email) {
            $emails[] = ['type' => $this->primaryType($email['types'], ['work', 'home'], 'other'), 'value' => trim($email['value'])];
        }

        $phones = [];
        foreach ($card->phones as $phone) {
            $normalized = IdentifierNormalizer::phone($phone['value']);
            if ($normalized === null) {
                continue;
            }
            $phones[] = ['type' => $this->phoneType($phone['types']), 'value' => $normalized];
        }

        $addresses = [];
        foreach ($card->addresses as $address) {
            $street = trim(implode(' ', array_filter([$address['street'], $address['extended']], static fn (string $v): bool => $v !== '')));
            $addresses[] = [
                'type' => $this->primaryType($address['types'], ['work', 'home'], 'other'),
                'street' => $street,
                'postal_code' => $address['postal_code'],
                'city' => $address['city'],
                'region' => $address['region'],
                'country' => $address['country'],
            ];
        }

        $isCompany = $this->isCompany($card);
        [$firstName, $lastName] = $isCompany ? [null, null] : $this->names($card);
        $primaryAddress = $this->preferred($addresses, ['work', 'home']);

        return [
            'kind' => $isCompany ? 'company' : 'person',
            'salutation' => $this->nullIfEmpty($card->name['prefix'] ?? ''),
            'first_name' => $firstName,
            'last_name' => $isCompany ? $card->organization : $lastName,
            'company' => $card->organization,
            'job_title' => $card->title,
            'email' => $this->preferred($emails, ['work', 'home'])['value'] ?? null,
            'phone' => $this->firstPhone($phones, ['work', 'home', 'other']),
            'mobile' => $this->firstPhone($phones, ['cell']),
            'street' => $primaryAddress['street'] ?? null,
            'postal_code' => $this->nullIfEmpty($primaryAddress['postal_code'] ?? ''),
            'city' => $this->nullIfEmpty($primaryAddress['city'] ?? ''),
            'country' => $this->nullIfEmpty($primaryAddress['country'] ?? ''),
            'notes' => $card->note,
            'emails' => $emails,
            'phones' => $phones,
            'addresses' => $addresses,
            'categories' => $card->categories,
            'extra' => $card->extra,
            'vcard_uid' => $card->uid,
            'vcard_rev' => $card->rev,
            'vcard_version' => $card->version,
            'external_id' => $this->externalId($external),
            'uid_missing' => ! $card->hasUid(),
            'href' => $href,
        ];
    }

    /**
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>
     */
    public function toExternal(array $local): array
    {
        throw new WriteBlockedException('CardDAV ist ausschließlich lesend, es gibt keine Abbildung nach vCard.', 'carddav.write');
    }

    /**
     * @param  array<string, mixed>  $external
     */
    public function externalId(array $external): string
    {
        $card = $this->card($external);

        if ($card->hasUid()) {
            return (string) $card->uid;
        }

        $href = (string) ($external['href'] ?? '');

        if ($href === '') {
            throw new \InvalidArgumentException('vCard ohne UID und ohne href kann nicht zugeordnet werden.');
        }

        return 'href:'.$href;
    }

    /**
     * Teilmenge der Attribute, die in die Prüfsumme eingeht (ohne href, ETag und Versionshinweise).
     *
     * @param  array<string, mixed>  $local
     * @return array<string, mixed>
     */
    public static function checksumPayload(array $local): array
    {
        unset($local['href'], $local['vcard_rev'], $local['vcard_version']);

        return $local;
    }

    /**
     * @param  array<string, mixed>  $external
     */
    private function card(array $external): VCard
    {
        $card = $external['vcard'] ?? null;

        if (! $card instanceof VCard) {
            throw new \InvalidArgumentException('Erwartet wird ein VCard-Objekt unter dem Schlüssel vcard.');
        }

        return $card;
    }

    /**
     * Firma: kein strukturierter Name (N) und ORG vorhanden, FN fehlt oder entspricht ORG.
     */
    private function isCompany(VCard $card): bool
    {
        if ($card->organization === null) {
            return false;
        }

        $hasName = $card->name !== null && (trim($card->name['given']) !== '' || trim($card->name['family']) !== '');

        if ($hasName) {
            return false;
        }

        return $card->formattedName === null || mb_strtolower(trim($card->formattedName)) === mb_strtolower(trim($card->organization));
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function names(VCard $card): array
    {
        $given = $this->nullIfEmpty(trim(($card->name['given'] ?? '').' '.($card->name['additional'] ?? '')));
        $family = $this->nullIfEmpty($card->name['family'] ?? '');

        if ($given !== null || $family !== null) {
            return [$given, $family];
        }

        if ($card->formattedName === null) {
            return [null, null];
        }

        $parts = preg_split('/\s+/', trim($card->formattedName)) ?: [];

        if (count($parts) === 1) {
            return [null, $parts[0]];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    /**
     * @param  array<int, string>  $types
     * @param  array<int, string>  $priority
     */
    private function primaryType(array $types, array $priority, string $fallback): string
    {
        foreach ($priority as $candidate) {
            if (in_array($candidate, $types, true)) {
                return $candidate;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<int, string>  $types
     */
    private function phoneType(array $types): string
    {
        if (in_array('cell', $types, true) || in_array('mobile', $types, true)) {
            return 'cell';
        }

        if (in_array('fax', $types, true)) {
            return 'fax';
        }

        return $this->primaryType($types, ['work', 'home'], 'other');
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $priority
     * @return array<string, mixed>|null
     */
    private function preferred(array $items, array $priority): ?array
    {
        if ($items === []) {
            return null;
        }

        foreach ($priority as $type) {
            foreach ($items as $item) {
                if (($item['type'] ?? null) === $type) {
                    return $item;
                }
            }
        }

        return $items[0];
    }

    /**
     * @param  array<int, array{type: string, value: string}>  $phones
     * @param  array<int, string>  $types
     */
    private function firstPhone(array $phones, array $types): ?string
    {
        foreach ($types as $type) {
            foreach ($phones as $phone) {
                if ($phone['type'] === $type) {
                    return $phone['value'];
                }
            }
        }

        return null;
    }

    private function nullIfEmpty(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }
}
