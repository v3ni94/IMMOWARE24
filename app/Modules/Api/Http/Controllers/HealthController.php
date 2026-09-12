<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Health\HealthService;
use App\Modules\Api\Support\ApiCaller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /health, /health/database, /health/queue, /health/immoware. Ohne Auth nur status,
 * Details nur mit API-Key im Scope admin.
 */
final class HealthController
{
    public function __construct(
        private readonly HealthService $health,
        private readonly ApiCaller $caller,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->health->aggregate();
        $detailed = $this->isAdmin($request);
        $checks = [];

        foreach ($result['checks'] as $name => $check) {
            $checks[$name] = $detailed ? $check : ['status' => $check['status']];
        }

        return $this->respond($result['status'], ['checks' => $checks], $detailed);
    }

    public function database(Request $request): JsonResponse
    {
        return $this->single($request, $this->health->database());
    }

    public function queue(Request $request): JsonResponse
    {
        return $this->single($request, $this->health->queue());
    }

    public function immoware(Request $request): JsonResponse
    {
        return $this->single($request, $this->health->immoware());
    }

    /**
     * @param  array{status: string, details: array<string, mixed>}  $check
     */
    private function single(Request $request, array $check): JsonResponse
    {
        $detailed = $this->isAdmin($request);

        return $this->respond($check['status'], $detailed ? ['details' => $check['details']] : [], $detailed);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function respond(string $status, array $extra, bool $detailed): JsonResponse
    {
        $payload = array_merge(['status' => $status], $extra);

        if ($detailed) {
            $payload['checked_at'] = CarbonImmutable::now()->toIso8601ZuluString('millisecond');
        }

        return new JsonResponse($payload, $this->health->httpStatus($status), ['Cache-Control' => 'no-store']);
    }

    private function isAdmin(Request $request): bool
    {
        $key = $this->caller->resolveOptional($request);

        return $key !== null && $this->caller->hasScope($request, 'admin');
    }
}
