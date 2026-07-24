<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'status',
        'color',
        'due_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
        ];
    }

    // -- Relationships --

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    // -- Progress --

    public function getProgressAttribute(): array
    {
        $tasks = $this->tasks()->withoutGlobalScopes()->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN status = "Completada" THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = "Cancelada" THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = "En Progreso" THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = "Por Verificar" THEN 1 ELSE 0 END) as to_verify,
            SUM(CASE WHEN status = "Pendiente" THEN 1 ELSE 0 END) as pending
        ')->first();

        $total = (int) $tasks->total;
        $completed = (int) $tasks->completed;
        $countable = $total - (int) $tasks->cancelled;

        return [
            'total' => $total,
            'completed' => $completed,
            'cancelled' => (int) $tasks->cancelled,
            'in_progress' => (int) $tasks->in_progress,
            'to_verify' => (int) $tasks->to_verify,
            'pending' => (int) $tasks->pending,
            'percentage' => $countable > 0 ? round(($completed / $countable) * 100) : 0,
        ];
    }

    public function getHealthAttribute(): string
    {
        if ($this->status === 'Completado') {
            return 'completed';
        }

        if ($this->status === 'Archivado' || $this->status === 'Pausado') {
            return 'paused';
        }

        $overdueTasks = $this->tasks()
            ->where('due_date', '<', now()->format('Y-m-d'))
            ->whereNotIn('status', ['Por Verificar', 'Completada', 'Cancelada'])
            ->count();

        if ($overdueTasks > 0) {
            return 'at_risk';
        }

        if ($this->due_date && $this->due_date->isPast()) {
            return 'at_risk';
        }

        return 'on_track';
    }

    // -- Scopes --

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'Activo');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', 'Archivado');
    }
}
