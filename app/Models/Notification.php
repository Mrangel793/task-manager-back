<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class Notification
 *
 * @property string $id
 * @property string $user_id
 * @property string $task_id
 * @property string $channel
 * @property string $type
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property string|null $failure_reason
 * @property int $retry_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @package App\Models
 */
class Notification extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'task_id',
        'channel',
        'type',
        'title',
        'message',
        'data',
        'status',
        'sent_at',
        'read_at',
        'failure_reason',
        'retry_count',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }

    /**
     * Get the user this notification belongs to.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the task this notification is about.
     *
     * @return BelongsTo
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Scope a query to only include pending notifications.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include failed notifications.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Mark notification as sent.
     *
     * @return bool
     */
    public function markAsSent(): bool
    {
        return $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark notification as failed.
     *
     * @param string $reason
     * @return bool
     */
    public function markAsFailed(string $reason): bool
    {
        return $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    /**
     * Mark notification as read.
     *
     * @return bool
     */
    public function markAsRead(): bool
    {
        return $this->update([
            'read_at' => now(),
        ]);
    }

    /**
     * Scope a query to only include unread notifications.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope a query to only include read notifications.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }
}
