<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\HashedIdentifier;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Estate\Models\BankAccount;
use App\Modules\Estate\Models\Property;
use App\Modules\Security\Services\PepperedHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * iban_hash und base_url_hash sind HMAC-SHA256 mit Pepper (02-data-model.md Konventionen, 08-security.md 2.2),
 * nie reines SHA-256, und werden aus dem jeweiligen Klartextfeld abgeleitet.
 */
final class HashedIdentifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_hash_is_hmac_with_pepper_and_differs_from_plain_sha256(): void
    {
        config()->set('hub.security.hashing.pepper', 'test-pepper');
        $identifier = new HashedIdentifier;

        $this->assertSame(hash_hmac('sha256', 'iban:DE02120300000000202051', 'test-pepper'), $identifier->iban('de02 1203 0000 0000 2020 51'));
        $this->assertSame(hash_hmac('sha256', 'base_url:https://dav.immoware24.de/x', 'test-pepper'), $identifier->baseUrl(' https://dav.immoware24.de/x '));
        $this->assertNotSame(hash('sha256', 'DE02120300000000202051'), $identifier->iban('DE02120300000000202051'));
        $this->assertNotSame((new HashedIdentifier('anderer-pepper'))->iban('DE02120300000000202051'), $identifier->iban('DE02120300000000202051'));
        $this->assertNull($identifier->iban(null));
        $this->assertNull($identifier->baseUrl(''));
        $this->assertTrue($identifier->equals('wert', $identifier->hash('wert')));
    }

    public function test_peppered_hasher_delegates_to_core_service(): void
    {
        $identifier = $this->app->make(HashedIdentifier::class);
        $hasher = $this->app->make(PepperedHasher::class);

        $this->assertSame($identifier->ip('10.1.2.3'), $hasher->hashIp('10.1.2.3'));
        $this->assertSame($identifier->baseUrl('https://dav.immoware24.de/a'), $hasher->hashBaseUrl('https://dav.immoware24.de/a'));
        $this->assertSame($identifier->iban('DE02120300000000202051'), $hasher->hashIban('DE02120300000000202051'));
    }

    public function test_connection_base_url_hash_is_derived_with_pepper_on_save(): void
    {
        $identifier = $this->app->make(HashedIdentifier::class);
        $connection = ImmowareConnection::factory()->create(['base_url' => 'https://dav.immoware24.de/share/1', 'base_url_hash' => hash('sha256', 'falsch')]);

        $this->assertSame($identifier->baseUrl('https://dav.immoware24.de/share/1'), $connection->fresh()->getAttribute('base_url_hash'));

        $connection->forceFill(['base_url' => 'https://dav.immoware24.de/share/2'])->save();
        $this->assertSame($identifier->baseUrl('https://dav.immoware24.de/share/2'), $connection->fresh()->getAttribute('base_url_hash'));
    }

    public function test_bank_account_iban_hash_is_derived_with_pepper_on_save(): void
    {
        $identifier = $this->app->make(HashedIdentifier::class);
        $organization = Organization::factory()->create();
        $property = Property::factory()->for($organization)->create();

        $account = new BankAccount;
        $account->forceFill([
            'organization_id' => $organization->getKey(),
            'owner_type' => 'property',
            'owner_id' => $property->getKey(),
            'iban' => 'DE02 1203 0000 0000 2020 51',
            'iban_hash' => hash('sha256', 'falsch'),
            'iban_masked' => BankAccount::maskIban('DE02120300000000202051'),
            'external_id' => 'bank-1',
            'checksum' => str_repeat('0', 64),
        ]);
        $account->save();

        $stored = BankAccount::query()->whereKey($account->getKey())->firstOrFail();
        $this->assertSame($identifier->iban('DE02120300000000202051'), $stored->getAttribute('iban_hash'));
        $this->assertNotSame(hash('sha256', 'DE02120300000000202051'), $stored->getAttribute('iban_hash'));
        $this->assertSame('DE02120300000000202051', preg_replace('/\s+/', '', (string) $stored->getAttribute('iban')));
    }
}
