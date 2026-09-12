<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Boot\BootGuard;
use App\Modules\Security\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Schreib-Flags gemäß docs/immoware/05-write-capabilities.md: Standard überall false, hart gesperrte Flags stoppen den Boot.
 */
final class WriteFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_write_flags_default_to_false(): void
    {
        $this->assertFalse((bool) config('hub.core.write.enabled'));
        $this->assertFalse((bool) config('hub.core.write.webdav_create_enabled'));

        foreach (array_keys(BootGuard::HARD_LOCKED_FLAGS) as $path) {
            $this->assertFalse((bool) config('hub.core.'.$path), $path);
        }
    }

    public function test_document_upload_returns_403_problem_json_while_write_flags_are_false(): void
    {
        Http::fake();
        $organization = $this->createOrganization();
        $plain = 'hub_live_'.Str::random(8).'_'.Str::random(43);
        ApiKey::factory()->for($organization)->withPlainKey($plain)->scopes(['documents:write'])->create();

        $response = $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('HUBTEST_rechnung.pdf', 12, 'application/pdf'),
            'filename' => 'HUBTEST_rechnung.pdf',
        ], ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json', 'Idempotency-Key' => 'flags-1']);

        $response->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 403)
            ->assertJsonPath('code', 'write_disabled');

        $this->assertStringContainsString('IMMOWARE_WRITE_ENABLED', (string) $response->json('detail'));
        $this->assertDatabaseCount('write_operations', 0);
        Http::assertNothingSent();
    }

    public function test_boot_guard_throws_when_delete_flag_is_true(): void
    {
        $core = (array) config('hub.core');
        $core['write']['webdav_delete_enabled'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED');

        (new BootGuard)->assertSafe($core);
    }

    public function test_boot_guard_reports_every_hard_locked_flag(): void
    {
        $core = (array) config('hub.core');

        foreach (BootGuard::HARD_LOCKED_FLAGS as $path => $env) {
            $violating = $core;
            data_set($violating, $path, 'true');
            $this->assertSame([$env], (new BootGuard)->violations($violating));
        }
    }
}
