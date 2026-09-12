<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Query\ListQuery;
use App\Modules\Api\Http\Resources\ApiResponse;
use App\Modules\Api\Support\ResourceRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lesende Endpunkte aller Spiegel- und Hub-Ressourcen (index, show) auf Basis der ResourceRegistry.
 */
final class ResourceController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ListQuery $listQuery,
        private readonly ApiResponse $response,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $resource = $this->resourceName($request);
        $definition = $this->registry->get($resource);
        $query = $this->baseQuery($definition->model, $definition->with);

        $paginator = $this->listQuery->apply($query, $request, $definition);

        return $this->response->collection($paginator, $definition, $request, $this->listQuery->fields($request));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $resource = $this->resourceName($request);
        $definition = $this->registry->get($resource);
        $query = $this->baseQuery($definition->model, $definition->with);

        /** @var Model $model */
        $model = $query->whereKey((int) $id)->firstOrFail();

        if ($resource === 'contacts' && $model->getAttribute('merged_into_id') !== null) {
            $target = $definition->href().'/'.(int) $model->getAttribute('merged_into_id');

            return $this->response->single($model, $definition, $request, $this->listQuery->fields($request), 301, ['Location' => $target]);
        }

        return $this->response->single($model, $definition, $request, $this->listQuery->fields($request));
    }

    /**
     * Der Ressourcenname kommt aus den Route-Defaults (Route::defaults('resource', ...)).
     */
    private function resourceName(Request $request): string
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->parameter('resource') : null;

        if (! is_string($name) || ! $this->registry->has($name)) {
            throw new NotFoundHttpException;
        }

        return $name;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $with
     * @return Builder<Model>
     */
    private function baseQuery(string $model, array $with): Builder
    {
        /** @var Builder<Model> $query */
        $query = $model::query();

        if ($with !== []) {
            $query->with($with);
        }

        return $query;
    }
}
