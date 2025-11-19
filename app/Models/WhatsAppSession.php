<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class WhatsAppSession
 *
 * @property string $id
 * @property string $user_id
 * @property string $whatsapp_phone
 * @property string|null $verification_code
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @package App\Models
 */
class WhatsAppSession extends Model
{
    use HasFactory, HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'whatsapp_sessions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'whatsapp_phone',
        'verification_code',
        'verified_at',
        'is_active',
        'last_message_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'is_active' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Get the user this session belongs to.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a verification code.
     *
     * @return string
     */
    public function generateVerificationCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->update([
            'verification_code' => $code,
            'verified_at' => null,
        ]);

        return $code;
    }

    /**
     * Verify the session with a code.
     *
     * @param string $code
     * @return bool
     */
    public function verify(string $code): bool
    {
        if ($this->verification_code === $code) {
            $this->update([
                'verified_at' => now(),
                'verification_code' => null,
                'is_active' => true,
            ]);

            // Also update user's whatsapp_verified
            $this->user->update([
                'whatsapp_verified' => true,
            ]);

            return true;
        }

        return false;
    }
}
