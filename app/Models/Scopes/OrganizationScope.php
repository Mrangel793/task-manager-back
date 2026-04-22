<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrganizationScope implements Scope
{
    /**
     * @param bool $failClosed  Cuando true, si no hay contexto de organización la query
     *                          retorna vacío (seguro por defecto). Usar false sólo en modelos
     *                          que Sanctum o procesos sin contexto necesiten consultar libremente
     *                          (ej. User para resolución de tokens).
     */
    public function __construct(private bool $failClosed = true) {}

    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = app()->bound('current_organization_id')
            ? app('current_organization_id')
            : null;

        if ($organizationId) {
            $builder->where($model->getTable() . '.organization_id', $organizationId);
        } elseif ($this->failClosed) {
            // Sin contexto de organización en un modelo sensible: no exponer ningún dato.
            $builder->whereRaw('0 = 1');
        }
        // failClosed=false: sin filtro (ej. User — Sanctum resuelve tokens sin contexto de org).
    }
}
