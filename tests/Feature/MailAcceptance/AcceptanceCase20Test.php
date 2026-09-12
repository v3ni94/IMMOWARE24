<?php

declare(strict_types=1);

namespace Tests\Feature\MailAcceptance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Abnahmefall 20: Bisherige Connector-Funktionen und bestehende Domains bestehen ihre Regressionstests.
 *
 * Der eigentliche Nachweis ist der grüne Lauf der gesamten Suite (php artisan test). Dieser Test sichert die
 * Rahmenbedingungen: die Bestandstests der Hub-Module sind weiterhin Teil der Suite, die Bestandstabellen und
 * Bestandsrouten des Hubs existieren unverändert, die Hub-API ist weiterhin durch api.auth geschützt, und das
 * Mail-Modul hat keine Route auf der Hub-Domain und keine Tabelle ohne Präfix mail_ hinzugefügt.
 */
final class AcceptanceCase20Test extends TestCase
{
    use RefreshDatabase;

    /** Bestandsmodule des Hubs mit eigenen Feature-Tests (vor dem Mail-Modul). */
    private const array LEGACY_TEST_DIRECTORIES = [
        'tests/Feature/Admin', 'tests/Feature/Api', 'tests/Feature/Calendar', 'tests/Feature/Connector', 'tests/Feature/Contacts',
        'tests/Feature/Documents', 'tests/Feature/Imports', 'tests/Feature/Security', 'tests/Feature/Sync', 'tests/Feature/Webhooks',
        'tests/Unit/Api', 'tests/Unit/Connector', 'tests/Unit/Documents', 'tests/Unit/Imports', 'tests/Unit/Security', 'tests/Unit/Sync',
        'tests/Contract',
    ];

    /** Bestandstabellen des Hubs (Auszug aus docs/architecture/02-data-model.md). */
    private const array LEGACY_TABLES = [
        'organizations', 'users', 'api_keys', 'audit_logs', 'immoware_connections', 'write_operations', 'proposed_changes',
        'documents', 'contacts', 'properties', 'cases', 'sync_runs', 'sync_states', 'dlq_items', 'webhook_endpoints', 'webhook_outbox', 'jobs', 'failed_jobs',
    ];

    public function test_legacy_test_suites_are_still_part_of_the_run(): void
    {
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));
        $this->assertStringContainsString('<directory>tests/Unit</directory>', $phpunit);
        $this->assertStringContainsString('<directory>tests/Feature</directory>', $phpunit);
        $this->assertStringContainsString('<directory>tests/Contract</directory>', $phpunit);

        foreach (self::LEGACY_TEST_DIRECTORIES as $directory) {
            $files = glob(base_path($directory).'/*Test.php') ?: [];
            $this->assertNotEmpty($files, sprintf('Bestandstests unter %s fehlen.', $directory));
        }
    }

    public function test_legacy_tables_exist_and_mail_module_only_adds_prefixed_tables(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), sprintf('Bestandstabelle %s fehlt.', $table));
        }

        $this->assertTrue(Schema::hasColumns('cases', ['immoware_ticket_reference', 'source_system']), 'Bestandstabelle cases unverändert.');
        $this->assertTrue(Schema::hasColumns('audit_logs', ['row_hash', 'prev_hash']), 'Hash-Kette des Auditlogs vorhanden.');

        $mailMigrations = glob(database_path('migrations').'/*_mail_*.php') ?: [];
        $this->assertNotEmpty($mailMigrations);

        foreach ($mailMigrations as $file) {
            preg_match_all("/Schema::create\\('([a-z_]+)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $table) {
                $this->assertStringStartsWith('mail_', $table, sprintf('Migration %s legt Tabelle %s ohne Präfix mail_ an.', basename($file), $table));
            }
        }
    }

    public function test_hub_routes_and_api_protection_are_unchanged_on_hub_domain(): void
    {
        $this->getJson('https://immoware.muellerhv.de/health')->assertOk()->assertJsonPath('status', 'ok');

        $this->getJson('https://immoware.muellerhv.de/api/v1')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->getJson('https://immoware.muellerhv.de/api/v1/cases')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->get('https://immoware.muellerhv.de/admin')->assertRedirect();

        $mailDomain = (string) config('hub.mail.domain');

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = (string) $route->uri();

            if (str_starts_with($uri, 'api/')) {
                $this->assertContains('api.auth', $route->gatherMiddleware(), sprintf('API-Route %s ohne api.auth.', $uri));
            }

            // Mail-Oberfläche nur auf der Mail-Domain; der Push-Endpunkt ohne Sitzung ist per Host-Middleware gebunden (PushEndpointTest).
            if (str_starts_with((string) $route->getName(), 'mail.') && ! str_starts_with($uri, 'mail/gmail/push')) {
                $this->assertSame($mailDomain, $route->getDomain(), sprintf('Mail-Route %s liegt nicht auf der Mail-Domain.', $uri));
            }
        }
    }
}
