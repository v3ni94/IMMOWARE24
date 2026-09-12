<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Resources;

use App\Core\Support\CorrelationId;
use App\Modules\Api\Support\Provenance;
use App\Modules\Api\Support\ResourceDefinition;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Baut die Antworthüllen {data, meta, links} für Listen und Einzelressourcen.
 */
final class ApiResponse
{
    public function __construct(
        private readonly Provenance $provenance,
        private readonly CorrelationId $correlationId,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, Model>|CursorPaginator<int, Model>  $paginator
     * @param  array<int, string>|null  $fields
     */
    public function collection(LengthAwarePaginator|CursorPaginator $paginator, ResourceDefinition $definition, Request $request, ?array $fields = null): JsonResponse
    {
        $items = [];
        $worst = 'fresh';

        foreach ($paginator->items() as $model) {
            if (! $model instanceof Model) {
                continue;
            }

            $item = (new EntityResource($model, $definition, $this->provenance, $fields))->toArray($request);

            if (($item['provenance']['stale'] ?? false) === true) {
                $worst = 'stale';
            }

            $items[] = $item;
        }

        $source = [
            'system' => $definition->hubOwned ? 'hub' : 'immoware24',
            'access_path' => $definition->accessPath,
            'evidence_status' => $definition->evidenceStatus,
            'source_status' => $worst,
        ];

        if ($paginator instanceof CursorPaginator) {
            $query = $request->query();
            $withCursor = static function (?string $cursor) use ($request, $query): ?string {
                if ($cursor === null) {
                    return null;
                }

                $query['cursor'] = $cursor;

                return $request->url().'?'.http_build_query($query);
            };
            $next = $paginator->nextCursor()?->encode();
            $prev = $paginator->previousCursor()?->encode();

            return new JsonResponse([
                'data' => $items,
                'meta' => [
                    'per_page' => $paginator->perPage(),
                    'count' => count($items),
                    'next_cursor' => $next,
                    'prev_cursor' => $prev,
                    'request_id' => $this->correlationId->current(),
                    'source' => $source,
                ],
                'links' => [
                    'self' => $request->fullUrl(),
                    'next' => $withCursor($next),
                    'prev' => $withCursor($prev),
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $query = $request->query();
        $withPage = static function (int $page) use ($request, $query): string {
            $query['page'] = $page;

            return $request->url().'?'.http_build_query($query);
        };

        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'request_id' => $this->correlationId->current(),
                'source' => $source,
            ],
            'links' => [
                'self' => $withPage($paginator->currentPage()),
                'first' => $withPage(1),
                'last' => $withPage(max(1, $paginator->lastPage())),
                'next' => $paginator->hasMorePages() ? $withPage($paginator->currentPage() + 1) : null,
                'prev' => $paginator->currentPage() > 1 ? $withPage($paginator->currentPage() - 1) : null,
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<int, string>|null  $fields
     * @param  array<string, string>  $headers
     */
    public function single(Model $model, ResourceDefinition $definition, Request $request, ?array $fields = null, int $status = 200, array $headers = []): JsonResponse
    {
        $data = (new EntityResource($model, $definition, $this->provenance, $fields))->toArray($request);

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'request_id' => $this->correlationId->current(),
                'source' => [
                    'system' => $data['provenance']['source_system'],
                    'access_path' => $definition->accessPath,
                    'evidence_status' => $definition->evidenceStatus,
                    'source_status' => $data['provenance']['stale'] ? 'stale' : 'fresh',
                    'data_age_seconds' => $data['provenance']['data_age_seconds'],
                    'last_synced_at' => $data['provenance']['last_synced_at'],
                ],
            ],
        ], $status, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public function raw(array $data, array $meta = [], int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => array_merge(['request_id' => $this->correlationId->current()], $meta),
        ], $status, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
