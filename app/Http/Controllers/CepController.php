<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CepController extends Controller
{
    /**
     * Consulta informações de endereço a partir de um CEP.
     *
     * FLUXO DE SEGURANÇA E PERFORMANCE:
     * 1. Sanitização estrita para dígitos numéricos e checagem de tamanho (exatos 8 dígitos).
     * 2. Cache inteligente de 24h para consultas com sucesso (economiza cota e zera latência).
     * 3. TTL curto (60s) para CEPs não encontrados (impede bloqueio duradouro por erro momentâneo).
     * 4. Timeout estrito de 5s na chamada externa para não reter workers PHP.
     * 5. Captura de exceções retornando mensagem controlada (sem expor stack trace).
     *
     * GET /api/v1/cep/{cep}
     */
    public function consultar(string $cep): JsonResponse
    {
        // 1. Sanitiza caracteres não numéricos
        $cleanCep = preg_replace('/\D/', '', $cep);

        // [LIÇÃO DE SEGURANÇA]: Validação estrita de tamanho antes de qualquer chamada ou chave de cache
        if (strlen($cleanCep) !== 8) {
            return response()->json([
                'message' => 'CEP inválido. O CEP deve conter exatamente 8 dígitos numéricos.',
            ], 422);
        }

        $cacheKey = "viacep_{$cleanCep}";

        // 2. Consulta no Cache (Redis/File)
        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey));
        }

        // 3. Consulta à API externa do ViaCEP com timeout explícito
        try {
            $http = config('app.env') === 'production'
                ? Http::timeout(5)
                : Http::withoutVerifying()->timeout(5);

            $response = $http->get("https://viacep.com.br/ws/{$cleanCep}/json/");

            if ($response->failed()) {
                Log::warning('Falha na resposta do ViaCEP', [
                    'status' => $response->status(),
                    'cep' => $cleanCep,
                ]);

                return response()->json([
                    'message' => 'Não foi possível consultar o CEP no momento. Tente novamente mais tarde.',
                ], 502);
            }

            $data = $response->json();

            // Se o ViaCEP retornar { "erro": true } ou string "true"
            if (! empty($data['erro'])) {
                // Cache curto (60s) para evitar sobrecarga de requisições repetidas em CEP inválido
                Cache::put($cacheKey, ['erro' => true], now()->addSeconds(60));

                return response()->json([
                    'message' => 'CEP não encontrado na base dos Correios.',
                ], 404);
            }

            // Normaliza o payload para compatibilidade com o frontend
            $payload = [
                'cep' => $data['cep'] ?? $cleanCep,
                'logradouro' => $data['logradouro'] ?? '',
                'complemento' => $data['complemento'] ?? '',
                'bairro' => $data['bairro'] ?? '',
                'cidade' => $data['localidade'] ?? '',
                'localidade' => $data['localidade'] ?? '',
                'estado' => $data['uf'] ?? '',
                'uf' => $data['uf'] ?? '',
            ];

            // 4. Armazena no cache por 24 horas apenas em caso de sucesso
            Cache::put($cacheKey, $payload, now()->addHours(24));

            return response()->json($payload);

        } catch (\Throwable $e) {
            Log::error('Exceção ao consultar ViaCEP', [
                'cep' => $cleanCep,
                'error_type' => get_class($e),
            ]);

            return response()->json([
                'message' => 'Serviço de consulta de CEP temporariamente indisponível.',
            ], 503);
        }
    }
}
