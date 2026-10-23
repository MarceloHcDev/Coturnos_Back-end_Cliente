<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'address_id' => ['required', 'integer', 'exists:addresses,id'],
            'shipping_service_id' => ['required'],
            'discount_fee' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.size' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'address_id.required' => 'O endereço de entrega é obrigatório.',
            'address_id.exists' => 'O endereço selecionado não é válido.',
            'shipping_service_id.required' => 'O serviço de frete é obrigatório.',
            'items.required' => 'O pedido deve conter ao menos um item.',
            'items.min' => 'O pedido deve conter ao menos um item.',
            'items.*.product_id.required' => 'O produto é obrigatório.',
            'items.*.product_id.exists' => 'Produto não encontrado.',
            'items.*.size.required' => 'O tamanho do coturno é obrigatório.',
            'items.*.quantity.required' => 'A quantidade é obrigatória.',
            'items.*.quantity.min' => 'A quantidade mínima por item é 1.',
        ];
    }
}
