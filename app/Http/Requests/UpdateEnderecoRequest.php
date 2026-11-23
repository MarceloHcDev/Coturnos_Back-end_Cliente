<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEnderecoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepara e normaliza os dados antes da validação.
     */
    protected function prepareForValidation(): void
    {
        $rawCep = $this->input('cep') ?? $this->input('zip_code') ?? $this->input('zip');
        $sanitizedCep = $rawCep !== null ? preg_replace('/\D/', '', (string) $rawCep) : null;

        $street = $this->input('street') ?? $this->input('logradouro');
        $number = $this->input('number') ?? $this->input('numero');
        $complement = $this->input('complement') ?? $this->input('complemento');
        $neighborhood = $this->input('neighborhood') ?? $this->input('bairro');
        $city = $this->input('city') ?? $this->input('cidade');
        $state = $this->input('state') ?? $this->input('estado');

        $isDefault = $this->input('is_default');
        if ($isDefault === null && $this->has('padrao')) {
            $isDefault = $this->boolean('padrao');
        } elseif ($isDefault === null && $this->has('isPrimary')) {
            $isDefault = $this->boolean('isPrimary');
        }

        $mergeData = array_filter([
            'zip_code' => $sanitizedCep,
            'street' => $street,
            'number' => $number,
            'complement' => $complement,
            'neighborhood' => $neighborhood,
            'city' => $city,
            'state' => $state !== null ? strtoupper(trim((string) $state)) : null,
        ], fn ($val) => $val !== null);

        if ($isDefault !== null) {
            $mergeData['is_default'] = (bool) $isDefault;
        }

        $this->merge($mergeData);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zip_code' => ['sometimes', 'required', 'string', 'size:8'],
            'street' => ['sometimes', 'required', 'string', 'max:120'],
            'number' => ['sometimes', 'required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'neighborhood' => ['sometimes', 'required', 'string', 'max:60'],
            'city' => ['sometimes', 'required', 'string', 'max:60'],
            'state' => ['sometimes', 'required', 'string', 'size:2'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zip_code.size' => 'O CEP deve conter exatamente 8 dígitos numéricos.',
            'street.max' => 'O logradouro não pode ter mais de 120 caracteres.',
            'number.max' => 'O número não pode ter mais de 20 caracteres.',
            'neighborhood.max' => 'O bairro não pode ter mais de 60 caracteres.',
            'city.max' => 'A cidade não pode ter mais de 60 caracteres.',
            'state.size' => 'O estado (UF) deve ter exatamente 2 letras.',
        ];
    }
}
