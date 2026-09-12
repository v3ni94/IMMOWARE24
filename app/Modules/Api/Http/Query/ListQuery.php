<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Query;

use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Support\ResourceDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;

/**
 * Wendet Filter (property_id, unit_id, contact_id, updated_since, external_id, status, q und
 * ressourcenspezifische Filter), Sortierung (?sort=-updated_at) und Pagination an: Offset (page, per_page,
 * max 500) oder Cursor (?cursor=, opaque Base64url-JSON aus Sortierschlüssel und id, 09 Abschnitt 2.2).
 * Nie Model::all(), immer paginate() oder cursorPaginate().
 */
final class ListQuery
{
    /** @var array<int, string> */
    public const array GLOBAL_FILTERS = ['property_id', 'unit_id', 'contact_id', 'updated_since', 'external_id', 'status', 'q', 'include_deleted'];

    public const string CURSOR_PARAMETER = 'cursor';

    /**
     * @param  Builder<Model>  $query
     * @return LengthAwarePaginator<int, Model>|CursorPaginator<int, Model>
     */
    public function apply(Builder $query, Request $request, ResourceDefinition $definition): LengthAwarePaginator|CursorPaginator
    {
        $this->applyFilters($query, $request, $definition);
        $this->applySort($query, $request, $definition);

        if ($this->wantsCursor($request)) {
            [, $perPage] = $this->pagination($request);

            return $query->cursorPaginate($perPage, ['*'], self::CURSOR_PARAMETER, $this->cursor($request));
        }

        [$page, $perPage] = $this->pagination($request);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Cursor-Modus, sobald der Parameter cursor vorhanden ist (auch leer: erste Seite im Cursor-Modus).
     */
    public function wantsCursor(Request $request): bool
    {
        return $request->query->has(self::CURSOR_PARAMETER);
    }

    private function cursor(Request $request): ?Cursor
    {
        $raw = $request->query(self::CURSOR_PARAMETER);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $cursor = Cursor::fromEncoded($raw);

        if ($cursor === null) {
            throw ApiProblemException::badRequest('invalid_cursor', 'Der Cursor ist ungültig oder gehört zu einer anderen Sortierung.');
        }

        return $cursor;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function pagination(Request $request): array
    {
        $default = (int) config('hub.api.pagination.default_per_page', 100);
        $max = (int) config('hub.api.pagination.max_per_page', 500);

        $perPage = $request->query('per_page');
        $page = $request->query('page');

        $perPage = $perPage === null || $perPage === '' ? $default : (int) $perPage;
        $page = $page === null || $page === '' ? 1 : (int) $page;

        if ($perPage < 1) {
            throw ApiProblemException::badRequest('invalid_filter', 'per_page muss mindestens 1 sein.');
        }

        if ($page < 1) {
            throw ApiProblemException::badRequest('invalid_filter', 'page muss mindestens 1 sein.');
        }

        return [$page, min($perPage, $max)];
    }

    /**
     * @return array<int, string>|null null bedeutet alle Felder
     */
    public function fields(Request $request): ?array
    {
        $raw = $request->query('fields');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $fields = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $f): bool => $f !== ''));

        return $fields === [] ? null : $fields;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyFilters(Builder $query, Request $request, ResourceDefinition $definition): void
    {
        $model = $query->getModel();
        $table = $model->getTable();

        if ($request->query('include_deleted') === 'true' && method_exists($model, 'getDeletedAtColumn')) {
            $query->withTrashed();
        }

        $updatedSince = $request->query('updated_since');

        if (is_string($updatedSince) && $updatedSince !== '') {
            try {
                $since = CarbonImmutable::parse($updatedSince);
            } catch (\Throwable) {
                throw ApiProblemException::badRequest('invalid_filter', 'updated_since muss ein ISO-8601-Zeitstempel sein.');
            }

            $query->where($table.'.updated_at', '>=', $since->utc());
        }

        $externalId = $request->query('external_id');

        if (is_string($externalId) && $externalId !== '' && ! $definition->hubOwned) {
            $query->where($table.'.external_id_hash', hash('sha256', $externalId));
        }

        $status = $request->query('status');

        if (is_string($status) && $status !== '' && $definition->statusColumn !== null) {
            $query->where($table.'.'.$definition->statusColumn, $status);
        }

        foreach (['property_id', 'unit_id', 'contact_id'] as $global) {
            if (! isset($definition->filters[$global])) {
                continue;
            }

            $value = $request->query($global);

            if ($value === null || $value === '') {
                continue;
            }

            if (! ctype_digit((string) $value)) {
                throw ApiProblemException::badRequest('invalid_filter', sprintf('%s muss eine Ganzzahl sein.', $global));
            }

            $this->applyColumnFilter($query, $definition->filters[$global], (int) $value);
        }

        foreach ($definition->filters as $name => $column) {
            if (in_array($name, ['property_id', 'unit_id', 'contact_id'], true)) {
                continue;
            }

            $value = $request->query($name);

            if ($value === null || $value === '' || ! is_string($value)) {
                continue;
            }

            $this->applyColumnFilter($query, $column, $value);
        }

        $q = $request->query('q');

        if (is_string($q) && trim($q) !== '' && $definition->searchable !== []) {
            $needle = '%'.$this->escapeLike(trim($q)).'%';

            $query->where(function (Builder $inner) use ($definition, $needle, $table): void {
                foreach ($definition->searchable as $column) {
                    // Explizites ESCAPE: Backslash wirkt unter SQLite nur mit ESCAPE-Klausel, unter MariaDB nur im Standard-SQL-Mode.
                    $inner->orWhereRaw($table.'.'.$column." like ? escape '!'", [$needle]);
                }
            });
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyColumnFilter(Builder $query, string $column, int|string $value): void
    {
        if (str_contains($column, '.')) {
            [$relation, $relationColumn] = explode('.', $column, 2);

            $query->whereHas($relation, static function (Builder $related) use ($relationColumn, $value): void {
                $related->where($related->getModel()->qualifyColumn($relationColumn), $value);
            });

            return;
        }

        $query->where($query->getModel()->qualifyColumn($column), $value);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySort(Builder $query, Request $request, ResourceDefinition $definition): void
    {
        $raw = $request->query('sort');
        $table = $query->getModel()->getTable();
        $sorts = is_string($raw) && trim($raw) !== '' ? array_filter(array_map('trim', explode(',', $raw))) : ['-updated_at'];
        $usedId = false;

        foreach ($sorts as $sort) {
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $field = ltrim($sort, '-+');

            if (! in_array($field, $definition->sortable, true)) {
                throw ApiProblemException::badRequest('invalid_filter', sprintf('Sortierung nach "%s" ist nicht erlaubt. Erlaubt: %s.', $field, implode(', ', $definition->sortable)));
            }

            $query->orderBy($table.'.'.$field, $direction);
            $usedId = $usedId || $field === 'id';
        }

        if (! $usedId) {
            $query->orderBy($table.'.id', 'asc');
        }
    }

    /**
     * LIKE-Sonderzeichen mit dem portablen Escape-Zeichen ! maskieren (ESCAPE '!' in der Abfrage; ein Backslash
     * wird von SQLite und MariaDB im String-Literal unterschiedlich behandelt).
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
