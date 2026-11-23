<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasFactory;

    protected $table = 'social_accounts';

    protected $fillable = [
        'provider_name',
        'provider_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Busca rápida de conta social vinculada pelo provedor e ID externo.
     */
    public static function findByProvider(string $provider, string $providerId): ?self
    {
        return static::where('provider_name', $provider)
            ->where('provider_id', $providerId)
            ->first();
    }
}
