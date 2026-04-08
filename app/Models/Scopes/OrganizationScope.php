<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = app()->bound('current_organization_id')
            ? app('current_organization_id')
            : null;

        if ($organizationId) {
            $builder->where($model->getTable() . '.organization_id', $organizationId);
        }
        // Sin contexto (ej. resolución de token Sanctum, comandos Artisan):
        // no se aplica filtro para no romper la autenticación.
    }
}
