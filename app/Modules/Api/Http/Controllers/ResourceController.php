<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Http\Query\ListQuery;
use App\Modules\Api\Http\Resources\ApiResponse;
use App\Modules\Api\Support\ApiCaller;
use App\Modules\Api\Support\ResourceDefinition;
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
        private readonly ApiCaller $caller,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $resource = $this->resourceName($request);
        $definition = $this->registry->get($resource);
        $query = $this->baseQuery($definition->model, $definition->with);
        $this->scopeToOrganization($query, $definition, $request);

        $paginator = $this->listQuery->apply($query, $request, $definition);

        return $this->response->collection($paginator, $definition, $request, $this->listQuery->fields($request));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $resource = $this->resourceName($request);
        $definition = $this->registry->get($resource);
        $query = $this->baseQuery($definition->model, $definition->with);
        $this->scopeToOrganization($query, $definition, $request);

        /** @var Model $model */
        $model = $query->whereKey((int) $id)->firstOrFail();

        if ($resource === 'contacts' && $model->getAttribute('merged_into_id') !== null) {
            $target = $definition->href().'/'.(int) $model->getAttribute('merged_into_id');

            return $this->response->single($model, $definition, $request, $this->listQuery->fields($request), 301, ['Location' => $target]);
        }

        return $this->response->single($model, $definition, $request, $this->listQuery->fields($request));
    }

    /**
     * Mandantenscope für Modelle ohne Global Scope organization (z. B. conflicts über die Connection).
     *
     * @param  Builder<Model>  $query
     */
    private function scopeToOrganization(Builder $query, ResourceDefinition $definition, Request $request): void
    {
        if (! $definition->needsOrganizationScope()) {
            return;
        }

        $organizationId = $this->caller->organizationId($request);

        if ($organizationId === null) {
            throw ApiProblemException::forbidden('role_forbidden', 'Kein Mandantenkontext.');
        }

        $query->where(static function (Builder $inner) use ($definition, $organizationId): void {
            if ($definition->organizationColumn !== null) {
                $inner->where($inner->getModel()->qualifyColumn($definition->organizationColumn), $organizationId);
            }

            if ($definition->organizationVia !== null) {
                $method = $definition->organizationColumn !== null ? 'orWhereHas' : 'whereHas';
                $inner->{$method}($definition->organizationVia, static function (Builder $related) use ($organizationId): void {
                    $related->withoutGlobalScopes()->where($related->getModel()->qualifyColumn('organization_id'), $organizationId);
                });
            }
        });
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
