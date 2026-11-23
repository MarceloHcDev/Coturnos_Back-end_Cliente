<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreteResource extends JsonResource
{
    /**
     * Transforma a resposta da cotação de frete em um contrato padronizado.
     *
     * CONTRATO DE API COM O FRONTEND:
     * - valor: float estrito (nunca string como "R$ 23,90")
     * - prazo_dias: int estrito (nunca string como "5 dias")
     * - disponivel: boolean estrito
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'servico' => (string) ($this['servico'] ?? ''),
            'codigo' => (string) ($this['codigo'] ?? ''),
            'valor' => (float) ($this['valor'] ?? 0.0),
            'prazo_dias' => (int) ($this['prazo_dias'] ?? 0),
            'disponivel' => (bool) ($this['disponivel'] ?? true),
        ];
    }
}
