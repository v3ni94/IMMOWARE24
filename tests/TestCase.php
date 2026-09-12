<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Enums\Role;
use App\Core\Support\OrganizationContext;
use App\Core\Support\UrlGuard;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxPermission;
use App\Modules\Mail\Models\Team;
use App\Modules\Mail\Models\TeamMember;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Öffentliche Beispieladresse (Dokumentationsbereich ist gesperrt, deshalb eine reale öffentliche Adresse). */
    public const string FAKE_PUBLIC_IP = '93.184.216.34';

    /** Zuletzt über actingAsMailRole() verwendetes Postfach. */
    protected ?Mailbox $mailbox = null;

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

    /**
     * Mail-Modul: Team anlegen (mail_teams).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createTeam(?Organization $organization = null, array $attributes = []): Team
    {
        $organization ??= $this->createOrganization();
        $name = (string) ($attributes['name'] ?? 'Team '.fake()->unique()->word());

        return Team::query()->create(array_merge([
            'organization_id' => $organization->getKey(),
            'name' => $name,
            'slug' => Str::slug($name),
        ], $attributes));
    }

    /**
     * Mail-Modul: Postfach anlegen. Mit $user wird der Nutzer als Teammitglied mit $role (admin, lead, agent,
     * approver, auditor) eingetragen und erhält die Postfachrechte aus hub.mail.mailbox_permission_defaults.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createMailbox(?User $user = null, ?string $role = null, ?Team $team = null, array $attributes = []): Mailbox
    {
        $organizationId = $user?->getAttribute('organization_id') ?? $team?->getAttribute('organization_id') ?? $this->createOrganization()->getKey();
        $organization = Organization::query()->findOrFail($organizationId);
        $team ??= $this->createTeam($organization);

        $mailbox = Mailbox::query()->create(array_merge([
            'organization_id' => $organization->getKey(),
            'team_id' => $team->getKey(),
            'label' => 'Testpostfach',
            'email_address' => fake()->unique()->safeEmail(),
            'legal_entity_code' => 'HVM',
            'status' => 'configured',
        ], $attributes));

        if ($user !== null) {
            $this->attachMailRole($user, $mailbox, $role ?? 'agent');
        }

        return $mailbox;
    }

    /**
     * Mail-Modul: meldet einen Nutzer mit Team-Rolle an (2FA in der Sitzung bestätigt) und setzt den Mandantenkontext.
     * Die Systemrolle folgt der Team-Rolle: admin = Administrator, auditor = Nur Lesen, sonst Operator.
     * Das Postfach ist danach über $this->mailbox erreichbar.
     */
    protected function actingAsMailRole(string $teamRole, ?Mailbox $mailbox = null, ?Role $systemRole = null): User
    {
        $systemRole ??= match ($teamRole) {
            'admin' => Role::Administrator,
            'auditor' => Role::ReadOnly,
            default => Role::Operator,
        };

        $organization = $mailbox === null
            ? $this->createOrganization()
            : Organization::query()->findOrFail($mailbox->getAttribute('organization_id'));

        $user = User::factory()->role($systemRole)->for($organization)->create();
        $mailbox ??= $this->createMailbox($user, $teamRole);

        if ($mailbox->wasRecentlyCreated === false) {
            $this->attachMailRole($user, $mailbox, $teamRole);
        }

        $this->mailbox = $mailbox;

        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
        $this->app->make(OrganizationContext::class)->set((int) $organization->getKey());

        return $user;
    }

    /**
     * Teammitgliedschaft plus Postfachrechte gemäß Vorgabe der Team-Rolle.
     */
    protected function attachMailRole(User $user, Mailbox $mailbox, string $teamRole): void
    {
        if ($mailbox->team_id !== null) {
            TeamMember::query()->updateOrCreate(
                ['team_id' => $mailbox->team_id, 'user_id' => $user->getKey()],
                ['team_role' => $teamRole],
            );
        }

        $defaults = (array) config('hub.mail.mailbox_permission_defaults.'.$teamRole, ['can_read' => true]);

        MailboxPermission::query()->updateOrCreate(
            ['mailbox_id' => $mailbox->getKey(), 'user_id' => $user->getKey()],
            $defaults + ['granted_at' => now()],
        );
    }
}
