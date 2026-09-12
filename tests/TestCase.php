<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Enums\Role;
use App\Core\Support\OrganizationContext;
use App\Core\Support\UrlGuard;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Öffentliche Beispieladresse (Dokumentationsbereich ist gesperrt, deshalb eine reale öffentliche Adresse). */
    public const string FAKE_PUBLIC_IP = '93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();

        // Keine DNS-Auflösung in Tests: Hosts mit Endung .invalid gelten als nicht auflösbar, alle anderen als öffentlich.
        $this->app->singleton(UrlGuard::class, static fn (): UrlGuard => new UrlGuard(
            static fn (string $host): array => str_ends_with($host, '.invalid') ? [] : [self::FAKE_PUBLIC_IP],
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createOrganization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createConnection(?Organization $organization = null, array $attributes = []): ImmowareConnection
    {
        $organization ??= $this->createOrganization();

        return ImmowareConnection::factory()
            ->for($organization)
            ->create($attributes);
    }

    /**
     * Meldet einen Nutzer mit der gewünschten Rolle an und setzt den Mandantenkontext.
     */
    protected function actingAsRole(Role $role, ?Organization $organization = null): User
    {
        $organization ??= $this->createOrganization();

        $user = User::factory()->role($role)->for($organization)->create();

        $this->actingAs($user);
        $this->app->make(OrganizationContext::class)->set((int) $organization->getKey());

        return $user;
    }
}
