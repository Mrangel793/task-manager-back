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
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the due_date attribute without timezone conversion.
     */
    public function getDueDateAttribute($value): ?string
    {
        return $value ?? ($this->attributes['due_date'] ?? null);
    }

    /**
     * Get due_date as Carbon instance for formatting (without timezone issues).
     */
    public function getDueDateCarbonAttribute(): ?\Carbon\Carbon
    {
        $dueDate = $this->attributes['due_date'] ?? null;
        if (!$dueDate) {
            return null;
        }
        // Create Carbon in app timezone to avoid conversion
        return \Carbon\Carbon::createFromFormat('Y-m-d', $dueDate, config('app.timezone'))
            ->startOfDay();
    }

    /**
     * Get formatted due date.
     */
    public function getFormattedDueDateAttribute(): ?string
    {
        return $this->due_date_carbon?->format('d/m/Y');
    }

    /**
     * Set the due_date attribute without timezone conversion.
     */
    public function setDueDateAttribute($value): void
    {
        // Store as-is if already a string in Y-m-d format
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->attributes['due_date'] = $value;
        } elseif ($value instanceof \DateTimeInterface) {
            $this->attributes['due_date'] = $value->format('Y-m-d');
        } elseif ($value) {
            // Parse and store just the date part
            $this->attributes['due_date'] = date('Y-m-d', strtotime($value));
        } else {
            $this->attributes['due_date'] = null;
        }
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
     * Uses today's date (not datetime) for consistent comparison.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', now()->format('Y-m-d'))
            ->whereNotIn('status', ['Por Verificar', 'Completada', 'Cancelada']);
    }

    /**
     * Check if task is overdue.
     * A task is overdue if its due_date is before today and it's not completed/cancelled.
     *
     * @return bool
     */
    public function isOverdue(): bool
    {
        if (!$this->due_date) {
            return false;
        }

        // Task is NOT overdue if already completed or cancelled
        if (in_array($this->status, ['Por Verificar', 'Completada', 'Cancelada'])) {
            return false;
        }

        // Compare date only (not datetime) for consistent behavior
        $dueDate = $this->due_date;
        $today = now()->format('Y-m-d');

        return $dueDate < $today;
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
            'Pendiente' => ['En Progreso', 'Completada', 'Cancelada'],
            'En Progreso' => ['Por Verificar', 'Completada', 'Pendiente', 'Cancelada'],
            'Por Verificar' => ['Completada', 'En Progreso', 'Cancelada'],
            'Completada' => [],
            'Cancelada' => ['Pendiente'],
        ];

        return in_array($newStatus, $validTransitions[$this->status] ?? []);
    }
}
