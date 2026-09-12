<?php

declare(strict_types=1);

namespace Tests\Feature\Lexware;

use App\Core\Support\SecretMasker;
use App\Modules\Lexware\DTO\CustomerLookupResult;
use App\Modules\Lexware\DTO\LexwareCredentials;
use App\Modules\Lexware\Exceptions\LexwareConflictException;
use App\Modules\Lexware\Exceptions\LexwareUnavailableException;
use App\Modules\Lexware\Services\LexwareClient;
use App\Modules\Lexware\Services\LexwareClientFactory;
use App\Modules\Lexware\Services\LexwareConnectionResolver;
use App\Modules\Lexware\Services\LexwareCustomerLookup;
use App\Modules\Lexware\Services\LexwareRateLimiter;
use App\Modules\Lexware\Support\LexwareContactRules;
use App\Modules\Lexware\Testing\FakeLexwareApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Lexware-Client (Http::fake, kein Live-Test): Rate Limit, 429 mit Retry-After, 409 Konflikt, Pagination,
 * Kundensuche (Abnahmefall 10: nicht erreichbar ist nicht "kein Kunde"). Endpunkte aus Snippets.
 */
final class LexwareClientTest extends TestCase
{
    use RefreshDatabase;

    private FakeLexwareApi $fake;

    private LexwareCredentials $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.lexware.rate_limit_rps', 1000);
        $this->fake = new FakeLexwareApi;
        $this->fake->install();
        $this->credentials = new LexwareCredentials('https://api.lexoffice.io/v1', 'test-key', 1, 1, 'HVM');
    }

    public function test_status_is_not_configured_without_api_key(): void
    {
        config()->set('hub.lexware.api_key', null);
        $resolver = $this->app->make(LexwareConnectionResolver::class);

        $this->assertSame('Nicht eingerichtet', $resolver->statusLabel(1));
        $this->assertSame(CustomerLookupResult::NOT_CONFIGURED, $this->app->make(LexwareCustomerLookup::class)->byEmail(1, 'HVM', 'x@example.com')->status);
    }

    public function test_get_put_with_version_and_conflict(): void
    {
        $this->fake->seedContact('c-1', ['person' => ['lastName' => 'Muster']], 3);
        $client = $this->app->make(LexwareClientFactory::class)->for($this->credentials);

        $contact = $client->getContact('c-1');
        $this->assertSame(3, $contact['version']);
        $this->assertNull($client->getContact('unbekannt'));

        $updated = $client->updateContact('c-1', $contact + ['note' => 'Neu']);
        $this->assertSame(4, $updated['version']);

        $this->expectException(LexwareConflictException::class);
        $client->updateContact('c-1', ['version' => 3, 'note' => 'Alt']);
    }

    public function test_update_without_version_is_refused_locally(): void
    {
        $client = $this->app->make(LexwareClientFactory::class)->for($this->credentials);

        $this->expectException(\InvalidArgumentException::class);
        $client->updateContact('c-1', ['note' => 'ohne Version']);
    }

    public function test_429_is_retried_after_retry_after_and_5xx_is_unavailable(): void
    {
        Sleep::fake();
        $this->fake->seedContact('c-1', [], 1);
        $this->fake->failNext('contacts/c-1', 429);
        $client = $this->app->make(LexwareClientFactory::class)->for($this->credentials);

        $this->assertSame(1, $client->getContact('c-1')['version']);
        Sleep::assertSleptTimes(1);

        $this->fake->failNext('contacts/c-1', 503);
        $this->expectException(LexwareUnavailableException::class);
        $client->getContact('c-1');
    }

    public function test_filter_contacts_reads_all_pages(): void
    {
        Http::fake([
            'https://paged-lexware.test/v1/contacts?*page=1*' => Http::response(['content' => [['id' => 'b']], 'totalPages' => 2, 'last' => true]),
            'https://paged-lexware.test/v1/contacts?*' => Http::response(['content' => [['id' => 'a']], 'totalPages' => 2, 'last' => false]),
        ]);
        $credentials = new LexwareCredentials('https://paged-lexware.test/v1', 'test-key', 2, 1, 'HVM');
        $client = new LexwareClient($this->app->make(Factory::class), $this->app->make(LexwareRateLimiter::class), $this->app->make(SecretMasker::class), $credentials);

        $this->assertSame(['a', 'b'], array_column($client->filterContacts(['email' => 'x@example.com']), 'id'));
    }

    public function test_lookup_unreachable_is_unclear_and_complete_empty_search_is_not_required(): void
    {
        config()->set('hub.lexware.api_key', 'env-key');
        $lookup = $this->app->make(LexwareCustomerLookup::class);

        $this->fake->failNext('contacts', 503);
        $unclear = $lookup->byEmail(1, 'HVM', 'mieter@example.com');
        $this->assertSame(CustomerLookupResult::UNCLEAR, $unclear->status);
        $this->assertSame('Ungeklärt (Lexware nicht erreichbar)', $unclear->label());
        $this->assertFalse($unclear->isDecided());

        $none = $lookup->byEmail(1, 'HVM', 'mieter@example.com');
        $this->assertSame(CustomerLookupResult::NOT_REQUIRED, $none->status);

        $this->fake->seedContact('c-9', ['person' => ['firstName' => 'Max', 'lastName' => 'Mieter'], 'emails' => ['business' => ['mieter@example.com']]], 2);
        $found = $lookup->byEmail(1, 'HVM', 'mieter@example.com');
        $this->assertSame(CustomerLookupResult::FOUND, $found->status);
        $this->assertSame('c-9', $found->contacts[0]['external_id']);
        $this->assertSame([], $this->fake->requests('POST'), 'Kein Anlegen von Kunden.');
    }

    public function test_contact_rules_detect_non_editable_contacts_and_keep_all_entries(): void
    {
        $rules = new LexwareContactRules;
        $multi = ['id' => 'x', 'version' => 1, 'addresses' => ['billing' => [['street' => 'A'], ['street' => 'B']], 'shipping' => []]];

        $this->assertFalse($rules->isApiEditable($multi));
        $this->assertTrue($rules->isApiEditable(['addresses' => ['billing' => [['street' => 'A']]]]));

        $payload = $rules->buildUpdatePayload(['version' => 2, 'roles' => ['customer' => []], 'addresses' => ['billing' => [['street' => 'A', 'zip' => '1']], 'shipping' => [['street' => 'S']]]], ['street' => 'Neu']);
        $this->assertSame('Neu', $payload['addresses']['billing'][0]['street']);
        $this->assertSame('1', $payload['addresses']['billing'][0]['zip']);
        $this->assertSame('S', $payload['addresses']['shipping'][0]['street']);
        $this->assertArrayHasKey('roles', $payload);
    }

    public function test_rate_limiter_blocks_third_request_within_a_second(): void
    {
        $limiter = new LexwareRateLimiter($this->app->make('cache.store'), 2.0);

        $this->assertTrue($limiter->tryAcquire('k'));
        $this->assertTrue($limiter->tryAcquire('k'));
        $this->assertFalse($limiter->tryAcquire('k'));
    }
}
