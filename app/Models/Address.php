<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'addresses';

    protected $fillable = [
        'zip_code',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Inicialização e eventos de ciclo de vida da model Address.
     *
     * Regra de Negócio (E-Commerce): Exclusividade do Endereço Padrão.
     * Se um endereço for marcado como is_default = true, todos os outros endereços
     * do mesmo usuário são automaticamente desmarcados, garantindo consistência.
     */
    protected static function booted(): void
    {
        static::saving(function (Address $address) {
            if ($address->is_default && $address->user_id) {
                static::where('user_id', $address->user_id)
                    ->where('id', '!=', (int) $address->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
