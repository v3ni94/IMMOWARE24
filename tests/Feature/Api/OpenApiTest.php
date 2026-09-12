<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Api\OpenApi\OpenApiGenerator;
use Illuminate\Support\Facades\Route;

final class OpenApiTest extends ApiTestCase
{
    public function test_openapi_contains_all_api_routes_and_extensions(): void
    {
        $this->issueKey(['properties:read']);

        $response = $this->getJson('/api/docs/openapi.json', $this->authHeaders());
        $response->assertOk();
        $spec = $response->json();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('apiKey', $spec['components']['securitySchemes']);
        $this->assertArrayHasKey('Problem', $spec['components']['schemas']);
        $this->assertArrayHasKey('Provenance', $spec['components']['schemas']);
        $this->assertArrayHasKey('document.created', $spec['webhooks']);
        $this->assertStringContainsString('Rate Limits', $spec['info']['description']);

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! ($uri === 'api/v1' || str_starts_with($uri, 'api/v1/') || $uri === 'health' || str_starts_with($uri, 'health/') || str_starts_with($uri, 'api/docs'))) {
                continue;
            }

            $path = '/'.$uri;
            $this->assertArrayHasKey($path, $spec['paths'], 'Route fehlt in OpenAPI: '.$path);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $operation = $spec['paths'][$path][strtolower($method)] ?? null;
                $this->assertNotNull($operation, 'Operation fehlt: '.$method.' '.$path);
                $this->assertArrayHasKey('x-scope', $operation);
                $this->assertArrayHasKey('x-effect', $operation);
                $this->assertArrayHasKey('x-phase', $operation);
            }
        }

        $this->assertSame(['properties:read'], $spec['paths']['/api/v1/properties']['get']['x-scope']);
        $this->assertSame('immoware24', $spec['paths']['/api/v1/documents']['post']['x-effect']);
        $this->assertSame('hub', $spec['paths']['/api/v1/contacts/{id}']['patch']['x-effect']);
    }

    public function test_docs_html_and_export_command(): void
    {
        $this->issueKey(['properties:read']);

        $this->get('/api/docs', $this->authHeaders())
            ->assertOk()
            ->assertSee('Immoware Hub API')
            ->assertDontSee('cdn.', false);

        $this->getJson('/api/docs/openapi.json')->assertStatus(401);

        $target = sys_get_temp_dir().'/hub-openapi-'.uniqid().'/openapi.json';

        $this->artisan('hub:openapi:export', ['--path' => $target])->assertSuccessful();

        $this->assertFileExists($target);
        $written = json_decode((string) file_get_contents($target), true);
        $this->assertSame($this->app->make(OpenApiGenerator::class)->generate()['paths'], $written['paths']);

        @unlink($target);
        @rmdir(dirname($target));
    }
}
