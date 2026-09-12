<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\OpenApi\OpenApiGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * GET /api/docs/openapi.json und GET /api/docs (HTML ohne externes CDN).
 */
final class DocsController
{
    public function __construct(private readonly OpenApiGenerator $generator) {}

    public function openapi(): Response
    {
        return new Response($this->generator->toJson(), 200, [
            'Content-Type' => 'application/vnd.oai.openapi+json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function html(): View
    {
        return view('api::docs', [
            'title' => (string) config('hub.api.openapi.title', 'Immoware Hub API'),
            'specUrl' => url('/api/docs/openapi.json'),
        ]);
    }
}
