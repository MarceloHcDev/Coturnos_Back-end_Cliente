<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FreteRequest extends FormRequest
{
    /**
     * Determina se o usuário está autorizado a fazer essa requisição.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepara e sanitiza os dados antes da validação.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('cep_destino')) {
            $this->merge([
                'cep_destino' => preg_replace('/\D/', '', (string) $this->input('cep_destino')),
            ]);
        }
    }

    /**
     * Regras de validação estritas para o cálculo de frete.
     *
     * [LIÇÃO DE SEGURANÇA]:
     * - Quantidade possui limite superior (max: 50) para impedir cálculo abusivo e DoS.
     * - Peso e dimensões NUNCA são aceitos nesta validação; eles são buscados do banco de dados.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cep_destino' => ['required', 'string', 'size:8'],
            'itens' => ['required', 'array', 'min:1', 'max:50'],
            'itens.*.produto_id' => ['required', 'integer', 'exists:products,id'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Mensagens amigáveis de validação.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cep_destino.required' => 'O CEP de destino é obrigatório.',
            'cep_destino.size' => 'O CEP de destino deve conter exatamente 8 dígitos numéricos.',
            'itens.required' => 'A lista de itens do carrinho é obrigatória para o cálculo.',
            'itens.min' => 'Ao menos um item deve ser informado para o cálculo de frete.',
            'itens.max' => 'Não é permitido calcular mais de 50 itens distintos em uma única requisição.',
            'itens.*.produto_id.required' => 'O ID do produto é obrigatório.',
            'itens.*.produto_id.exists' => 'Um ou mais produtos selecionados não foram encontrados no catálogo.',
            'itens.*.quantidade.required' => 'A quantidade é obrigatória.',
            'itens.*.quantidade.min' => 'A quantidade mínima por item é 1 unidade.',
            'itens.*.quantidade.max' => 'A quantidade máxima permitida por item para cálculo direto é de 50 unidades.',
        ];
    }
}
