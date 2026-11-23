<?php

namespace App\Services;

use App\Models\CoturnoEmbalado;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SuperFreteService
{
    protected string $baseUrl;

    protected ?string $token;

    protected string $fromPostalCode;

    public function __construct()
    {
        $this->baseUrl = config('services.super_frete.url', 'https://sandbox.superfrete.com/api/v0/calculator');
        $this->token = config('services.super_frete.token');
        $this->fromPostalCode = config('services.super_frete.from_postal_code', '01153000');
    }

    /**
     * Calcula o frete agregado para os itens do carrinho.
     *
     * [LIÇÃO DE SEGURANÇA]:
     * - Os pesos e dimensões NÃO vêm do request/payload do cliente.
     * - As dimensões são baseadas no modelo padrão CoturnoEmbalado multiplicado pela quantidade de itens válidos.
     *
     * @param  string  $cepDestino  CEP sanitizado com 8 dígitos
     * @param  array<int, array{produto_id: int, quantidade: int}>  $itens
     * @return array<int, array{servico: string, codigo: string, valor: float, prazo_dias: int, disponivel: bool}>
     */
    public function calcularFreteCarrinho(string $cepDestino, array $itens): array
    {
        // 1. Valida existência dos produtos no banco (catálogo oficial)
        $produtoIds = array_column($itens, 'produto_id');
        $produtosCount = Product::whereIn('id', $produtoIds)->count();

        if ($produtosCount !== count(array_unique($produtoIds))) {
            throw new \InvalidArgumentException('Um ou mais produtos selecionados não existem no catálogo.');
        }

        // 2. Calcula quantidade total e peso/dimensões agregadas
        $quantidadeTotal = 0;
        foreach ($itens as $item) {
            $quantidadeTotal += (int) $item['quantidade'];
        }

        // Dimensões padrão do CoturnoEmbalado (1.5kg, 14cm x 22cm x 35cm)
        $coturno = new CoturnoEmbalado;
        $pesoUnitario = (float) $coturno->peso;
        $alturaUnitaria = (float) $coturno->altura;
        $largura = (float) $coturno->largura;
        $comprimento = (float) $coturno->comprimento;

        $pesoTotal = round($quantidadeTotal * $pesoUnitario, 2);
        // Empilhamento vertical das caixas
        $alturaTotal = round($quantidadeTotal * $alturaUnitaria, 2);

        // Limites máximos dos Correios para uma única etiqueta: 30kg e 105cm por dimensão (máx 200cm na soma)
        $alturaAjustada = min($alturaTotal, 105.00);
        $pesoAjustado = min($pesoTotal, 30.00);

        $package = [
            'height' => number_format($alturaAjustada, 2, '.', ''),
            'width' => number_format($largura, 2, '.', ''),
            'length' => number_format($comprimento, 2, '.', ''),
            'weight' => number_format($pesoAjustado, 2, '.', ''),
        ];

        // 3. Chamada à API SuperFrete com timeout explícito de 5s e SSL estrito
        try {
            $response = Http::timeout(5)
                ->withToken($this->token)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'CoturnosECia/1.0 (contato@coturnosecia.com.br)',
                ])
                ->post($this->baseUrl, [
                    'from' => [
                        'postal_code' => $this->fromPostalCode,
                    ],
                    'to' => [
                        'postal_code' => $cepDestino,
                    ],
                    'services' => '1,2', // 1: PAC, 2: SEDEX
                    'options' => [
                        'own_hand' => false,
                        'receipt' => false,
                        'insurance_value' => 0,
                        'use_insurance_value' => false,
                    ],
                    'package' => $package,
                ]);

            if ($response->failed()) {
                Log::warning('SuperFrete retornou erro', [
                    'status' => $response->status(),
                    'cep_destino' => $cepDestino,
                ]);

                // Fallback SOMENTE se o desenvolvedor local não possuir token configurado no .env
                if (! app()->runningUnitTests() && empty($this->token) && config('app.env') === 'local') {
                    return $this->estimativaFreteLocal($cepDestino, $quantidadeTotal);
                }

                throw new \RuntimeException('Falha na cotação de frete junto à transportadora.');
            }

            $rawServices = $response->json();

            return $this->normalizarRetorno((array) $rawServices);

        } catch (\Throwable $e) {
            // [LIÇÃO DE SEGURANÇA]: Nunca logar headers ou tokens confidenciais
            Log::error('Erro ao calcular frete com SuperFrete', [
                'error' => $e->getMessage(),
                'cep_destino' => $cepDestino,
            ]);

            // Fallback SOMENTE se o desenvolvedor local não possuir token configurado no .env
            if (! app()->runningUnitTests() && empty($this->token) && config('app.env') === 'local') {
                return $this->estimativaFreteLocal($cepDestino, $quantidadeTotal);
            }

            throw $e;
        }
    }

    /**
     * Estimativa de contingência para ambiente local ou quando a SuperFrete estiver fora do ar.
     *
     * @return array<int, array{servico: string, codigo: string, valor: float, prazo_dias: int, disponivel: bool}>
     */
    protected function estimativaFreteLocal(string $cepDestino, int $quantidadeTotal): array
    {
        $prefixo = substr(preg_replace('/\D/', '', $cepDestino), 0, 2);
        $isSP = in_array($prefixo, ['01', '02', '03', '04', '05', '06', '07', '08', '09', '11', '12', '13', '14', '15', '16', '17', '18', '19'], true);
        $isSudeste = $isSP || in_array($prefixo, ['20', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32', '33', '34', '35', '36', '37', '38', '39'], true);

        $basePAC = $isSP ? 21.90 : ($isSudeste ? 28.50 : 36.90);
        $baseSEDEX = $isSP ? 32.50 : ($isSudeste ? 46.90 : 64.90);
        $adicional = max(0, $quantidadeTotal - 1) * 6.50;

        return [
            [
                'servico' => 'PAC',
                'codigo' => '1',
                'valor' => round($basePAC + $adicional, 2),
                'prazo_dias' => $isSP ? 5 : ($isSudeste ? 7 : 11),
                'disponivel' => true,
            ],
            [
                'servico' => 'SEDEX',
                'codigo' => '2',
                'valor' => round($baseSEDEX + $adicional, 2),
                'prazo_dias' => $isSP ? 2 : ($isSudeste ? 3 : 5),
                'disponivel' => true,
            ],
        ];
    }

    /**
     * Normaliza as opções retornadas pela SuperFrete para o contrato limpo da aplicação.
     *
     * @param  array<int, mixed>  $services
     * @return array<int, array{servico: string, codigo: string, valor: float, prazo_dias: int, disponivel: bool}>
     */
    protected function normalizarRetorno(array $services): array
    {
        $normalized = [];

        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $hasError = ! empty($service['error']) && $service['error'] !== false;
            $nome = (string) ($service['name'] ?? 'PAC');
            $id = (string) ($service['id'] ?? '1');

            $normalized[] = [
                'servico' => strtoupper($nome),
                'codigo' => $id,
                'valor' => $hasError ? 0.0 : (float) ($service['price'] ?? 0.0),
                'prazo_dias' => $hasError ? 0 : (int) ($service['delivery_time'] ?? 0),
                'disponivel' => ! $hasError,
            ];
        }

        // Se nenhum serviço veio na resposta da API, garante o fallback de PAC e SEDEX indisponíveis
        if (empty($normalized)) {
            $normalized = [
                [
                    'servico' => 'PAC',
                    'codigo' => '1',
                    'valor' => 0.0,
                    'prazo_dias' => 0,
                    'disponivel' => false,
                ],
                [
                    'servico' => 'SEDEX',
                    'codigo' => '2',
                    'valor' => 0.0,
                    'prazo_dias' => 0,
                    'disponivel' => false,
                ],
            ];
        }

        return $normalized;
    }
}
