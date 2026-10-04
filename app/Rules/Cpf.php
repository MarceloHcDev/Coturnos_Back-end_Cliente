<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cpf implements ValidationRule
{
    /**
     * Valida o formato e o dígito verificador do CPF segundo o algoritmo oficial da Receita Federal.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cpf = preg_replace('/\D/', '', (string) $value);

        if (strlen($cpf) !== 11) {
            $fail('O CPF informado deve conter 11 dígitos.');
            return;
        }

        // Rejeita sequências de dígitos idênticos (ex: 111.111.111-11, 000.000.000-00)
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            $fail('O CPF informado é inválido.');
            return;
        }

        // Validação dos dois dígitos verificadores
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += (int) $cpf[$c] * (($t + 1) - $c);
            }

            $d = ((10 * $d) % 11) % 10;

            if ((int) $cpf[$t] !== $d) {
                $fail('O CPF informado é inválido.');
                return;
            }
        }
    }
}
