<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:50'],
            'last_name' => ['sometimes', 'required', 'string', 'max:50'],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'regex:/^(\(?\d{2}\)?\s?)?(\d{4,5}\-?\d{4}|\d{10,11})$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'O primeiro nome é obrigatório.',
            'first_name.max' => 'O primeiro nome não pode ter mais de 50 caracteres.',
            'last_name.required' => 'O sobrenome é obrigatório.',
            'last_name.max' => 'O sobrenome não pode ter mais de 50 caracteres.',
            'phone.regex' => 'O telefone deve estar em um formato válido com DDD (10 ou 11 dígitos).',
        ];
    }
}
