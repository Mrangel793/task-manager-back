<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class TaskHistory
 *
 * @property string $id
 * @property string $task_id
 * @property string $user_id
 * @property string $action
 * @property array|null $from_value
 * @property array|null $to_value
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @package App\Models
 */
class TaskHistory extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'task_id',
        'user_id',
        'action',
        'from_value',
        'to_value',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_value' => 'array',
            'to_value' => 'array',
            'created_at' => 'datetime',
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

        static::creating(function ($history) {
            $history->created_at = now();
        });
    }

    /**
     * Get the task this history belongs to.
     *
     * @return BelongsTo
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who performed this action.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
