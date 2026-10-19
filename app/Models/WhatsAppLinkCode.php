<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Código de un solo uso para vincular el WhatsApp de un usuario con el agente.
 * Sólo se guarda el HMAC del código; el código en claro se entrega una vez.
 */
class WhatsAppLinkCode extends Model
{
    protected $table = 'whatsapp_link_codes';

    protected $fillable = [
        'user_id',
        'code_hash',
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
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
