<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Enums\Role;
use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Services\WriteGuard;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WriteGuard (Api) berücksichtigt Connector-Typ, Zweck und allowed_write_prefix (05-write-capabilities.md 2.2 Nr. 1,
 * Änderungsvermerk 12.09.2026): write_enabled allein genügt nie.
 */
final class WriteGuardConnectionScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        config()->set('hub.core.write.enabled', true);
        config()->set('hub.core.write.webdav_create_enabled', true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function approvedConnection(array $overrides): ImmowareConnection
    {
        $requester = User::factory()->role(Role::Administrator)->for($this->organization)->create();
        $confirmer = User::factory()->role(Role::Owner)->for($this->organization)->create();

        return ImmowareConnection::factory()->for($this->organization)->create($overrides + [
            'connector_type' => 'webdav_documents',
            'purpose' => 'write',
            'status' => 'active',
            'allowed_write_prefix' => '/Posteingang/',
            'write_enabled' => true,
            'write_enabled_by' => $requester->getKey(),
            'write_confirmed_by' => $confirmer->getKey(),
            'write_approval_document_id' => 1,
            'write_enabled_at' => now(),
        ]);
    }

    private function denialCode(ImmowareConnection $connection): ?string
    {
        try {
            $this->app->make(WriteGuard::class)->assertUploadAllowed((int) $connection->getKey(), (int) $this->organization->getKey());
        } catch (ApiProblemException $exception) {
            return $exception->problemCode;
        }

        return null;
    }

    public function test_non_webdav_connection_is_denied_even_with_full_approval(): void
    {
        $this->assertSame('write_disabled', $this->denialCode($this->approvedConnection(['connector_type' => 'carddav_contacts'])));
        $this->assertSame('write_disabled', $this->denialCode($this->approvedConnection(['connector_type' => 'caldav_calendar'])));
        $this->assertSame('write_disabled', $this->denialCode($this->approvedConnection(['connector_type' => 'file_import'])));
        $this->assertSame('write_disabled', $this->denialCode($this->approvedConnection(['purpose' => 'read'])));
    }

    public function test_invalid_write_prefix_is_denied(): void
    {
        foreach (['/', '/Dokumente/', 'Posteingang/', null] as $prefix) {
            $this->assertSame('write_prefix_invalid', $this->denialCode($this->approvedConnection(['allowed_write_prefix' => $prefix])), 'Prefix '.var_export($prefix, true));
        }
    }

    public function test_valid_scope_passes_to_capability_check(): void
    {
        // Ohne Capability-Zeile documents.write scheitert erst die Fähigkeitsprüfung, nicht mehr Typ, Zweck oder Prefix.
        $this->assertSame('capability_locked', $this->denialCode($this->approvedConnection(['connector_type' => 'webdav_inbox'])));
    }
}
