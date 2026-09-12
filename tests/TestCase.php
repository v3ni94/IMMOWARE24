<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Enums\Role;
use App\Core\Support\OrganizationContext;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
