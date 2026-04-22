<?php

namespace App\Models\Traits;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        // Los modelos pueden declarar `protected static bool $organizationScopeFailClosed = false`
        // para deshabilitar el fail-closed (necesario en User para resolución de tokens Sanctum).
        $failClosed = static::$organizationScopeFailClosed ?? true;
        static::addGlobalScope(new OrganizationScope($failClosed));

        static::creating(function ($model) {
            if (empty($model->organization_id)) {
                $organizationId = app()->bound('current_organization_id')
                    ? app('current_organization_id')
                    : null;

                if ($organizationId) {
                    $model->organization_id = $organizationId;
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
