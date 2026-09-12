<?php

declare(strict_types=1);

namespace App\Modules\Lexware\Services;

use App\Modules\Lexware\DTO\CustomerLookupResult;
use App\Modules\Lexware\Exceptions\LexwareNotConfiguredException;
use App\Modules\Lexware\Exceptions\LexwareUnavailableException;
use App\Modules\Lexware\Support\LexwareContactRules;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kundensuche in Lexware für einen Vorgang. Nichterreichbarkeit = "Ungeklärt" (nie "kein Kunde"), vollständige
 * erfolgreiche Suche ohne Treffer = "Nicht erforderlich". Es wird nie ein Kunde angelegt.
 */
final class LexwareCustomerLookup
{
    public function __construct(
        private readonly LexwareClientFactory $clients,
        private readonly LexwareContactRules $rules,
    ) {}

    public function byEmail(?int $organizationId, ?string $legalEntityCode, string $email): CustomerLookupResult
    {
        return $this->lookup($organizationId, $legalEntityCode, ['email' => strtolower(trim($email)), 'customer' => 'true']);
    }

    public function byName(?int $organizationId, ?string $legalEntityCode, string $name): CustomerLookupResult
    {
        return $this->lookup($organizationId, $legalEntityCode, ['name' => trim($name), 'customer' => 'true']);
    }

    /**
     * @param  array<string, scalar>  $filters
     */
    public function lookup(?int $organizationId, ?string $legalEntityCode, array $filters): CustomerLookupResult
    {
        try {
            $client = $this->clients->forOrganization($organizationId, $legalEntityCode);
        } catch (LexwareNotConfiguredException) {
            return new CustomerLookupResult(CustomerLookupResult::NOT_CONFIGURED, [], 'Lexware Office ist nicht eingerichtet.');
        }

        try {
            $contacts = $client->filterContacts($filters);
        } catch (LexwareUnavailableException $e) {
            Log::warning('Lexware-Kundensuche ohne Ergebnis (ungeklärt).', ['error' => $e->getMessage()]);

            return new CustomerLookupResult(CustomerLookupResult::UNCLEAR, [], $e->getMessage());
        } catch (Throwable $e) {
            return new CustomerLookupResult(CustomerLookupResult::UNCLEAR, [], 'Unerwarteter Fehler: '.class_basename($e));
        }

        if ($contacts === []) {
            return new CustomerLookupResult(CustomerLookupResult::NOT_REQUIRED, [], 'Vollständige Suche ohne Treffer.');
        }

        $summaries = array_map(fn (array $contact): array => array_intersect_key($this->rules->flatten($contact), array_flip(['external_id', 'version', 'name', 'city', 'billing_address_count'])), $contacts);

        return new CustomerLookupResult(CustomerLookupResult::FOUND, array_values($summaries));
    }
}
