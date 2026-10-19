<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código de un solo uso para vincular el WhatsApp de un usuario con el agente.
 * Sólo se guardan los HMAC del código y del número al que queda atado.
 */
class WhatsAppLinkCode extends Model
{
    use MassPrunable;

    protected $table = 'whatsapp_link_codes';

    protected $fillable = [
        'user_id',
        'code_hash',
        'phone_hash',
        'expires_at',
        'used_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', 'code:'.$code, (string) config('app.key'));
    }

    public static function hashPhone(string $phone): string
    {
        return hash_hmac('sha256', 'phone:'.$phone, (string) config('app.key'));
    }

    public static function maxFailedAttempts(): int
    {
        return (int) config('agent.link_max_failed_attempts', 5);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->expires_at->isFuture()
            && $this->attempts <= self::maxFailedAttempts();
    }

    public function scopeActiveForPhone(Builder $query, string $phoneHash): Builder
    {
        return $query->where('phone_hash', $phoneHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<=', self::maxFailedAttempts());
    }

    /**
     * Códigos usados o vencidos hace más de un día (php artisan model:prune).
     */
    public function prunable(): Builder
    {
        return static::where(function (Builder $q) {
            $q->whereNotNull('used_at')->orWhere('expires_at', '<', now()->subDay());
        });
    }
}
