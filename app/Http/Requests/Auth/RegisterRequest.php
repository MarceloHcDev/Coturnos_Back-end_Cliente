<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determina se o usuário está autorizado a fazer esta requisição.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepara os dados antes da validação:
     * 1. Divide 'name' (campo único enviado pelo front) em 'first_name' e 'last_name'
     * 2. Converte 'birthdate' (DD/MM/YYYY do front) para 'birth_date' (YYYY-MM-DD)
     * 3. Normaliza e-mail para minúsculas (evita duplicidade por casing)
     * 4. Gera o blind index (cpf_hash) para validação de unicidade
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        // 1. Suporte a payload com campo único 'name' (vindo do frontend React)
        if ($this->has('name') && ! $this->has('first_name')) {
            $name = trim((string) $this->input('name'));
            $parts = explode(' ', $name, 2);
            $merge['first_name'] = $parts[0] ?? '';
            $merge['last_name'] = $parts[1] ?? '';
        }

        // 2. Suporte a birthdate em formato brasileiro (DD/MM/YYYY)
        if ($this->has('birthdate') && ! $this->has('birth_date')) {
            $d = (string) $this->input('birthdate');
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $d, $m)) {
                $merge['birth_date'] = "{$m[3]}-{$m[2]}-{$m[1]}";
            } else {
                $merge['birth_date'] = $d;
            }
        }

        // 3. Normalização de e-mail para lowercase
        if ($this->has('email') && is_string($this->input('email'))) {
            $merge['email'] = strtolower(trim($this->input('email')));
        }

        // 4. Blind index para validação de unicidade
        if ($this->has('cpf')) {
            $merge['cpf_hash'] = User::generateCpfHash($this->input('cpf'));
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * Regras de validação para registro de novos clientes.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'cpf' => ['required', 'string', new Cpf()],
            'cpf_hash' => ['nullable', 'string', 'unique:users,cpf_hash'],
            'email' => ['required', 'string', 'email', 'max:191', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^\(?\d{2}\)?[\s-]?\d{4,5}[-\s]?\d{4}$/'],
            'birth_date' => [
                'nullable',
                'date',
                'before_or_equal:' . now()->subYears(16)->format('Y-m-d'),
                'after_or_equal:' . now()->subYears(110)->format('Y-m-d'),
            ],
            'password' => [
                'required',
                'string',
                'max:128',
                Password::min(8)->mixedCase()->symbols(),
                Rule::when($this->has('password_confirmation'), ['confirmed']),
            ],
            // Endereço opcional enviado no momento do cadastro pelo frontend
            'zip' => ['nullable', 'string', 'max:9'],
            'street' => ['nullable', 'string', 'max:120'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'neighborhood' => ['nullable', 'string', 'max:60'],
            'city' => ['nullable', 'string', 'max:60'],
            'state' => ['nullable', 'string', 'size:2'],
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
            'first_name.required' => 'O nome é obrigatório.',
            'cpf.required' => 'O CPF é obrigatório.',
            'cpf_hash.unique' => 'Este CPF já está cadastrado.',
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Este e-mail já está cadastrado.',
            'phone.required' => 'O telefone é obrigatório.',
            'phone.regex' => 'Informe um telefone brasileiro válido com DDD (ex: (11) 98765-4321).',
            'birth_date.before_or_equal' => 'É necessário ter pelo menos 16 anos para se cadastrar.',
            'birth_date.after_or_equal' => 'A data de nascimento informada é inválida.',
            'password.required' => 'A senha é obrigatória.',
            'password.max' => 'A senha não pode conter mais de 128 caracteres.',
            'password.confirmed' => 'A confirmação de senha não confere.',
        ];
    }
}
