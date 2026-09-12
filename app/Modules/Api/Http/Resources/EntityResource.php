<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Resources;

use App\Modules\Api\Support\Provenance;
use App\Modules\Api\Support\ResourceDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Generische Eloquent API Resource: Ausgabefelder laut ResourceDefinition, Sparse Fieldsets (?fields=)
 * und Herkunftsblock provenance. id und provenance werden immer ausgegeben.
 */
class EntityResource extends JsonResource
{
    /**
     * @param  array<int, string>|null  $fields
     */
    public function __construct(
        Model $resource,
        private readonly ResourceDefinition $definition,
        private readonly Provenance $provenance,
        private readonly ?array $fields = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Model $model */
        $model = $this->resource;
        $data = [];

        foreach ($this->definition->attributes as $attribute) {
            if ($this->fields !== null && $attribute !== 'id' && ! in_array($attribute, $this->fields, true)) {
                continue;
            }

            $data[$attribute] = $this->normalize($model, $attribute);
        }

        if ($this->fields === null || in_array('identity_uncertain', $this->fields, true)) {
            $confidence = $model->getAttribute('identity_confidence');

            if ($confidence !== null) {
                $data['identity_uncertain'] = $confidence === 'uncertain';
            }
        }

        $data['provenance'] = $this->provenance->for($model, $this->definition);

        return $data;
    }

    private function normalize(Model $model, string $attribute): mixed
    {
        $value = $model->getAttribute($attribute);

        if (! $value instanceof \DateTimeInterface) {
            return $value;
        }

        $cast = $model->getCasts()[$attribute] ?? null;
        $carbon = CarbonImmutable::instance($value);

        if (is_string($cast) && in_array(strtolower(explode(':', $cast)[0]), ['date', 'immutable_date'], true)) {
            return $carbon->toDateString();
        }

        return $carbon->utc()->toIso8601ZuluString('millisecond');
    }
}
