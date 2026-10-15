<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $current = app(CurrentTenant::class);
            $column = $builder->getModel()->getTable().'.tenant_id';

            if ($current->isSet()) {
                $builder->where($column, $current->id());
            } else {
                $builder->whereRaw('1 = 0');
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $current = app(CurrentTenant::class);

            if (! $current->isSet()) {
                throw new LogicException('Aucune entreprise active : impossible de créer '.$model::class.'.');
            }

            $model->setAttribute('tenant_id', $current->id());
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}