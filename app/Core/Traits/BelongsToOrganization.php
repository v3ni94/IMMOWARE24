<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Core\Support\OrganizationContext;
use App\Modules\Connector\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mandantenzuordnung. Der Global Scope greift nur, wenn ein OrganizationContext gesetzt ist
 * (Web-Request, API-Key). Hintergrundjobs ohne Kontext sehen alle Mandanten.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $context = app(OrganizationContext::class);

            if ($context->has()) {
                $builder->where($builder->getModel()->qualifyColumn('organization_id'), $context->get());
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('organization_id') === null) {
                $context = app(OrganizationContext::class);

                if ($context->has()) {
                    $model->setAttribute('organization_id', $context->get());
                }
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where($this->qualifyColumn('organization_id'), $organizationId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAllOrganizations(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organization');
    }
}
