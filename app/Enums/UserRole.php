<?php

namespace App\Enums;

enum UserRole: string
{
    case CUSTOMER = 'customer';
    case ADMIN = 'admin';
    case CLIENTE = 'cliente'; // Alias mantido para compatibilidade com seeders e integrações

    /**
     * Retorna o nome amigável do perfil de acesso.
     */
    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER, self::CLIENTE => 'Cliente',
            self::ADMIN => 'Administrador',
        };
    }

    /**
     * Verifica se o perfil é de administrador.
     */
    public function isAdmin(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * Verifica se o perfil é de cliente.
     */
    public function isCustomer(): bool
    {
        return $this === self::CUSTOMER || $this === self::CLIENTE;
    }
}
