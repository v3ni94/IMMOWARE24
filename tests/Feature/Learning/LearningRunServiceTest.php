<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Modules\Imports\Models\ImportFormat;
use App\Modules\Learning\Enums\LearningKind;
use App\Modules\Learning\Enums\LearningRunStatus;
use App\Modules\Learning\Exceptions\NoConnectionForKindException;
use App\Modules\Learning\Services\LearningRunService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Documents\DocumentsTestHelpers;
use Tests\TestCase;

final class LearningRunServiceTest extends TestCase
{
    use DocumentsTestHelpers, RefreshDatabase;

    public function test_webdav_run_succeeds_and_links_diff_to_the_previous_successful_run(): void
    {
        config()->set('hub.documents.scan.roots', []);
        $connection = $this->readConnection();
        $base = $this->basePathOf($connection);
        $organizationId = (int) $connection->organization->getKey();

        Http::fake(fn (): PromiseInterface => Http::response($this->multistatus($base, [
            ['href' => '/', 'collection' => true],
        ]), 207));

        /** @var LearningRunService $service */
        $service = $this->app->make(LearningRunService::class);

        $first = $service->start($organizationId, LearningKind::WebDav, $connection->getKey(), null);
        $first = $service->execute($first);

        $this->assertSame(LearningRunStatus::Succeeded, $first->status());
        $this->assertTrue($first->showsDifferences(), 'Der erste Lauf gilt mangels Vergleich als Veränderung.');
        $this->assertNull($first->getAttribute('previous_run_id'));
        $this->assertNotNull($first->getAttribute('facts_fingerprint'));

        $second = $service->start($organizationId, LearningKind::WebDav, $connection->getKey(), null);
        $second = $service->execute($second);

        $this->assertSame(LearningRunStatus::Succeeded, $second->status());
        $this->assertSame($first->getKey(), $second->getAttribute('previous_run_id'));
        $this->assertFalse($second->showsDifferences(), 'Identische Rohbefunde ergeben keine Veränderung.');
    }

    public function test_webdav_run_without_a_matching_connection_fails_with_a_readable_error(): void
    {
        $organization = $this->createOrganization();
        $service = $this->app->make(LearningRunService::class);

        $this->expectException(NoConnectionForKindException::class);
        $service->resolveConnection(LearningKind::WebDav, (int) $organization->getKey(), null);
    }

    public function test_run_marks_failure_on_transport_error_without_throwing(): void
    {
        $connection = $this->readConnection();
        $organizationId = (int) $connection->organization->getKey();

        Http::fake(fn (): PromiseInterface => Http::response('kaputt', 500));

        $service = $this->app->make(LearningRunService::class);
        $run = $service->start($organizationId, LearningKind::WebDav, $connection->getKey(), null);
        $run = $service->execute($run);

        $this->assertSame(LearningRunStatus::Failed, $run->status());
        $this->assertNotNull($run->getAttribute('error_message'));
    }

    public function test_imports_run_reads_existing_import_formats_without_a_connection(): void
    {
        $organization = $this->createOrganization();

        ImportFormat::query()->create([
            'format_key' => 'properties', 'version' => 1, 'status' => 'active',
            'header_fingerprint' => hash('sha256', 'a'), 'header_columns' => ['id', 'name'],
            'column_mapping' => ['id' => 'id'], 'key_schema' => ['id'],
        ]);

        $service = $this->app->make(LearningRunService::class);
        $this->assertNull($service->resolveConnection(LearningKind::Imports, (int) $organization->getKey(), null));

        $run = $service->start((int) $organization->getKey(), LearningKind::Imports, null, null);
        $run = $service->execute($run);

        $this->assertSame(LearningRunStatus::Succeeded, $run->status());
        $this->assertSame(1, $run->getAttribute('facts_json')['format_count']);
        $this->assertArrayHasKey('properties', $run->getAttribute('facts_json')['formats']);
    }

    public function test_resolve_connection_honours_an_explicit_connection_id_of_the_right_type(): void
    {
        $connection = $this->readConnection();
        $service = $this->app->make(LearningRunService::class);

        $resolved = $service->resolveConnection(LearningKind::WebDav, (int) $connection->organization->getKey(), (int) $connection->getKey());

        $this->assertSame($connection->getKey(), $resolved->getKey());
    }
}
