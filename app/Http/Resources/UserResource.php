<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transforma o recurso em um array para retorno na API.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'cpf' => $this->maskCpf($this->cpf),
            'phone' => $this->phone,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'role' => $this->role instanceof UserRole ? $this->role->value : $this->role,
            'active' => (bool) $this->active,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Mascara o CPF conforme LGPD: ***.456.789-**
     */
    private function maskCpf(?string $cpf): ?string
    {
        if (! $cpf) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $cpf);

        if (strlen($digits) !== 11) {
            return $cpf;
        }

        return '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**';
    }
}
