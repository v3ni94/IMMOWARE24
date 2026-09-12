<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Support;

/**
 * Fachregeln für Lexware-Kontakte (aus Snippets, vor Produktivbetrieb am Original prüfen): Kontakte mit mehr als
 * einer Rechnungs- oder Lieferadresse oder mehreren Ansprechpersonen sind über die API nicht änderbar (PUT würde
 * Einträge verwerfen). Solche Kontakte gehen in den manuellen Klärungsweg; es werden niemals Einträge entfernt.
 */
final class LexwareContactRules
{
    /** @var array<int, string> Felder, die die Engine in der Rechnungsadresse ändern darf */
    public const array ADDRESS_FIELDS = ['street', 'zip', 'city', 'countryCode', 'supplement'];

    /**
     * @param  array<string, mixed>  $contact
     * @return array<int, string> Gründe, warum der Kontakt nicht per API änderbar ist (leer = änderbar)
     */
    public function nonEditableReasons(array $contact): array
    {
        $reasons = [];
        $addresses = (array) ($contact['addresses'] ?? []);

        foreach (['billing' => 'Rechnungsadressen', 'shipping' => 'Lieferadressen'] as $kind => $label) {
            $count = count((array) ($addresses[$kind] ?? []));

            if ($count > 1) {
                $reasons[] = sprintf('Kontakt hat %d %s; per API nur höchstens eine änderbar.', $count, $label);
            }
        }

        $persons = (array) ($contact['company']['contactPersons'] ?? []);

        if (count($persons) > 1) {
            $reasons[] = sprintf('Kontakt hat %d Ansprechpersonen; per API nicht änderbar (Snippet, am Original prüfen).', count($persons));
        }

        if (($contact['archived'] ?? false) === true) {
            $reasons[] = 'Kontakt ist archiviert.';
        }

        return $reasons;
    }

    public function isApiEditable(array $contact): bool
    {
        return $this->nonEditableReasons($contact) === [];
    }

    /**
     * Flache Sicht auf die Rechnungsadresse für Alt/Neu und Verifikation.
     *
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    public function flatten(array $contact): array
    {
        $billing = (array) (((array) ($contact['addresses'] ?? []))['billing'] ?? []);
        $first = is_array($billing[0] ?? null) ? $billing[0] : [];

        return [
            'found' => true,
            'external_id' => (string) ($contact['id'] ?? ''),
            'version' => isset($contact['version']) ? (int) $contact['version'] : null,
            'street' => $first['street'] ?? null,
            'zip' => $first['zip'] ?? null,
            'city' => $first['city'] ?? null,
            'countryCode' => $first['countryCode'] ?? null,
            'supplement' => $first['supplement'] ?? null,
            'note' => $contact['note'] ?? null,
            'billing_address_count' => count($billing),
            'shipping_address_count' => count((array) (((array) ($contact['addresses'] ?? []))['shipping'] ?? [])),
            'name' => $contact['company']['name'] ?? trim(((string) ($contact['person']['firstName'] ?? '')).' '.((string) ($contact['person']['lastName'] ?? ''))),
        ];
    }

    /**
     * Baut den PUT-Payload aus dem frisch gelesenen Kontakt: nur zugelassene Felder werden ersetzt, alles andere
     * bleibt unverändert (keine Entfernung von Adressen, Personen, Rollen).
     *
     * @param  array<string, mixed>  $fresh
     * @param  array<string, mixed>  $newValues
     * @return array<string, mixed>
     */
    public function buildUpdatePayload(array $fresh, array $newValues): array
    {
        $payload = $fresh;
        $addressFields = array_intersect_key($newValues, array_flip(self::ADDRESS_FIELDS));

        if ($addressFields !== []) {
            $billing = (array) (((array) ($payload['addresses'] ?? []))['billing'] ?? []);
            $first = is_array($billing[0] ?? null) ? $billing[0] : [];
            $billing[0] = array_merge($first, $addressFields);
            $payload['addresses'] = array_merge((array) ($payload['addresses'] ?? []), ['billing' => array_values($billing)]);
        }

        if (array_key_exists('note', $newValues)) {
            $payload['note'] = $newValues['note'];
        }

        return $payload;
    }
}
