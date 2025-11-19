<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Class Task
 *
 * @property string $id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property \Illuminate\Support\Carbon $due_date
 * @property string $due_time
 * @property string $assignee_id
 * @property string $creator_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @package App\Models
 */
class Task extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'due_time',
        'assignee_id',
        'creator_id',
        'started_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'due_time' => 'datetime:H:i',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($task) {
            if (empty($task->id)) {
                $task->id = self::generateUniqueId();
            }
        });
    }

    /**
     * Generate a unique 8-character ID.
     *
     * @return string
     */
    protected static function generateUniqueId(): string
    {
        do {
            $id = strtoupper(Str::random(8));
        } while (self::where('id', $id)->exists());

        return $id;
    }

    /**
     * Get the user assigned to this task.
     *
     * @return BelongsTo
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Get the user who created this task.
     *
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Get history for this task.
     *
     * @return HasMany
     */
    public function history(): HasMany
    {
        return $this->hasMany(TaskHistory::class);
    }

    /**
     * Get histories for this task (alias for history).
     *
     * @return HasMany
     */
    public function histories(): HasMany
    {
        return $this->history();
    }

    /**
     * Get notifications for this task.
     *
     * @return HasMany
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Scope a query to only include pending tasks.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'Pendiente');
    }

    /**
     * Scope a query to only include in-progress tasks.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', 'En Progreso');
    }

    /**
     * Scope a query to only include completed tasks.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'Completada');
    }

    /**
     * Scope a query to only include overdue tasks.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', now())
            ->whereNotIn('status', ['Completada', 'Cancelada']);
    }

    /**
     * Check if task can transition to a new status.
     *
     * @param string $newStatus
     * @return bool
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $validTransitions = [
            'Pendiente' => ['En Progreso', 'Cancelada'],
            'En Progreso' => ['Completada', 'Pendiente', 'Cancelada'],
            'Completada' => [],
            'Cancelada' => ['Pendiente'],
        ];

        return in_array($newStatus, $validTransitions[$this->status] ?? []);
    }
}
