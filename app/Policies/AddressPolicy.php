<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;

class AddressPolicy
{
    /**
     * Determina se o usuário pode visualizar qualquer endereço.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determina se o usuário pode visualizar o endereço.
     */
    public function view(User $user, Address $address): bool
    {
        return (int) $user->id === (int) $address->user_id;
    }

    /**
     * Determina se o usuário pode criar endereços.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determina se o usuário pode atualizar o endereço.
     */
    public function update(User $user, Address $address): bool
    {
        return (int) $user->id === (int) $address->user_id;
    }

    /**
     * Determina se o usuário pode excluir o endereço.
     */
    public function delete(User $user, Address $address): bool
    {
        return (int) $user->id === (int) $address->user_id;
    }
}
