<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Tabela associada ao modelo.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * Atributos atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'cpf',
        'email',
        'phone',
        'birth_date',
        'password',
        'role',
        'active',
    ];

    /**
     * Atributos ocultados na serialização (JSON).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Casts de tipos nativos e criptografia LGPD.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'password' => 'hashed',
            'active' => 'boolean',
            'cpf' => 'encrypted',   // Criptografia AES-256-CBC nativa via APP_KEY (LGPD)
            'phone' => 'encrypted', // Criptografia AES-256-CBC nativa via APP_KEY (LGPD)
        ];
    }

    /**
     * Accessor para retornar o nome completo do cliente.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Verifica se o usuário é administrador.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Verifica se o usuário é cliente.
     */
    public function isCustomer(): bool
    {
        return in_array($this->role, ['customer', 'cliente']);
    }

    /**
     * Relacionamento 1:N com endereços do usuário.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Relacionamento 1:N com redes sociais vinculadas (Google, Facebook, Apple).
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
