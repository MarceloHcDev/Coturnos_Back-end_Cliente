<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
     * Valores padrão seguros dos atributos da model.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::CUSTOMER->value,
        'active' => true,
    ];

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
    ];

    /**
     * Atributos ocultados na serialização padrão da model (JSON).
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'cpf',
        'phone',
        'cpf_hash',
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
            'role' => UserRole::class, // Enum tipado do PHP 8.2 / Laravel 11
            'cpf' => 'encrypted',      // Criptografia AES-256-CBC nativa via APP_KEY (LGPD)
            'phone' => 'encrypted',    // Criptografia AES-256-CBC nativa via APP_KEY (LGPD)
        ];
    }

    /**
     * Inicialização e eventos de ciclo de vida da model.
     *
     * REGRAS DE INTEGRIDADE E SEGURANÇA (E-COMMERCE / LGPD):
     * 1. Blind Indexing: Gera automaticamente hash HMAC-SHA256 para unicidade e buscas em CPF criptografado.
     * 2. Imutabilidade do CPF: O CPF é o identificador fiscal único (utilizado para emissão de NF-e
     *    e antifraude nos gateways de pagamento). Uma vez cadastrado, NÃO pode ser alterado pelo cliente.
     * 3. Atualizações Cadastrais:
     *    - Dados comuns (nome, sobrenome, telefone, data de nascimento): Atualizados via UpdateProfileRequest na Semana 2.
     *    - E-mail e Senha: Por motivos de segurança contra invasão de conta, serão atualizados exclusivamente
     *      em fluxos separados que exigem validação de senha atual e/ou código de verificação por e-mail.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('cpf') && ! empty($user->cpf)) {
                $user->cpf_hash = static::generateCpfHash($user->cpf);
            }
        });

        static::updating(function (User $user) {
            if ($user->isDirty('cpf') && ! empty($user->getOriginal('cpf'))) {
                throw new \InvalidArgumentException('O CPF não pode ser alterado após o cadastro.');
            }
        });
    }

    /**
     * Gera um Blind Index (HMAC-SHA256) determinístico a partir dos dígitos do CPF.
     */
    public static function generateCpfHash(?string $cpf): ?string
    {
        if (empty($cpf)) {
            return null;
        }

        $clean = preg_replace('/\D/', '', (string) $cpf);

        return hash_hmac('sha256', $clean, (string) config('app.key'));
    }

    /**
     * Busca rápida e indexada por CPF utilizando o Blind Index.
     */
    public static function findByCpf(?string $cpf): ?self
    {
        $hash = static::generateCpfHash($cpf);

        if (! $hash) {
            return null;
        }

        return static::where('cpf_hash', $hash)->first();
    }

    /**
     * Accessor moderno (Laravel 11) para retornar o nome completo do cliente.
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * Verifica se o usuário é administrador.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    /**
     * Verifica se o usuário é cliente.
     */
    public function isCustomer(): bool
    {
        return $this->role instanceof UserRole && $this->role->isCustomer();
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

    /**
     * Retorna as abilities do token Sanctum com base no role do usuário.
     *
     * @return array<int, string>
     */
    public function tokenAbilities(): array
    {
        return $this->role instanceof UserRole
            ? $this->role->abilities()
            : ['customer'];
    }
}
