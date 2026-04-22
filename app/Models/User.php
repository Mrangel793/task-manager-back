<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * Class User
 *
 * @property string $id
 * @property string $phone
 * @property string $name
 * @property string $password
 * @property string $role
 * @property string|null $whatsapp_phone
 * @property bool $whatsapp_verified
 * @property bool $is_active
 * @property array|null $notification_preferences
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @package App\Models
 */
class User extends Authenticatable
{
    use BelongsToOrganization, HasApiTokens, HasFactory, HasPushSubscriptions, HasRoles, HasUuids, Notifiable, SoftDeletes;

    /**
     * Sanctum resuelve tokens vía User::find() sin contexto de organización,
     * por eso User no puede ser fail-closed en el OrganizationScope.
     * Los lugares que necesitan consultas cross-org ya usan withoutGlobalScopes().
     */
    protected static bool $organizationScopeFailClosed = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'email',
        'phone',
        'name',
        'password',
        'role',
        'whatsapp_phone',
        'whatsapp_verified',
        'is_active',
        'notification_preferences',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'whatsapp_verified' => 'boolean',
            'is_active' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Get tasks assigned to this user.
     *
     * @return HasMany
     */
    public function tasksAssigned(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    /**
     * Get tasks created by this user.
     *
     * @return HasMany
     */
    public function tasksCreated(): HasMany
    {
        return $this->hasMany(Task::class, 'creator_id');
    }

    /**
     * Get notifications for this user.
     *
     * @return HasMany
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get WhatsApp session for this user.
     *
     * @return HasOne
     */
    public function whatsappSession(): HasOne
    {
        return $this->hasOne(WhatsAppSession::class);
    }

    /**
     * Get task history for this user.
     *
     * @return HasMany
     */
    public function taskHistory(): HasMany
    {
        return $this->hasMany(TaskHistory::class);
    }

    /**
     * Get notification preferences with defaults.
     *
     * @return array
     */
    public function getNotificationPreferencesAttribute($value): array
    {
        $defaults = [
            'web_push' => true,
            'whatsapp' => true,
            'email' => false,
            'task_assigned' => true,
            'task_reminder' => true,
            'task_due_soon' => true,
            'task_overdue' => true,
            'status_changed' => false,
        ];

        $preferences = $value ? json_decode($value, true) : [];

        return array_merge($defaults, $preferences ?? []);
    }

    /**
     * Hash the password before saving.
     *
     * @param string $value
     * @return void
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = Hash::needsRehash($value) ? Hash::make($value) : $value;
    }
}
