<?php

declare(strict_types=1);

namespace App\Modules\Api\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Deklarative Beschreibung einer API-Ressource: Modell, Scope, Filter, Sortierung, Ausgabefelder.
 * Grundlage für Controller, Directory-Antwort und OpenAPI-Generator.
 */
final readonly class ResourceDefinition
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $attributes  Ausgabefelder
     * @param  array<string, string>  $filters  Filtername => Spalte oder relation.spalte
     * @param  array<int, string>  $sortable  Erlaubte Sortierfelder
     * @param  array<int, string>  $searchable  Spalten für ?q=
     * @param  array<int, string>  $methods
     * @param  array<int, string>  $with  Eager Loading für Ausgabe
     */
    public function __construct(
        public string $name,
        public string $model,
        public string $entityType,
        public string $scope,
        public string $accessPath,
        public string $evidenceStatus,
        public array $attributes,
        public array $filters = [],
        public array $sortable = ['updated_at', 'id'],
        public array $searchable = [],
        public array $methods = ['GET'],
        public array $with = [],
        public bool $hubOwned = false,
        public string $description = '',
        public int $phase = 1,
        public ?string $statusColumn = 'status',
    ) {}

    public function singular(): string
    {
        return rtrim(str_replace('-', '_', $this->name), 's');
    }

    public function href(): string
    {
        return '/api/'.config('hub.api.version', 'v1').'/'.$this->name;
    }
}
