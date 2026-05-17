<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Contact extends Model
{
    use BelongsToOrganization;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'name',
        'phone',
        'email',
        'address',
        'notes',
        'source',
        'created_by',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($contact) {
            if (empty($contact->id)) {
                do {
                    $id = strtoupper(Str::random(8));
                } while (self::withoutGlobalScopes()->where('id', $id)->exists());

                $contact->id = $id;
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
