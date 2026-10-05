<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_valid_cep_returns_normalized_address_data(): void
    {
        Http::fake([
            'https://viacep.com.br/ws/01310100/json/' => Http::response([
                'cep' => '01310-100',
                'logradouro' => 'Avenida Paulista',
                'complemento' => 'de 1 a 610 - lado ímpar',
                'bairro' => 'Bela Vista',
                'localidade' => 'São Paulo',
                'uf' => 'SP',
                'ibge' => '3550308',
                'ddd' => '11',
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/cep/01310100');

        $response->assertStatus(200)
            ->assertJson([
                'cep' => '01310-100',
                'logradouro' => 'Avenida Paulista',
                'bairro' => 'Bela Vista',
                'cidade' => 'São Paulo',
                'localidade' => 'São Paulo',
                'estado' => 'SP',
                'uf' => 'SP',
            ]);
    }

    public function test_masked_cep_is_sanitized_and_succeeds(): void
    {
        Http::fake([
            'https://viacep.com.br/ws/01310100/json/' => Http::response([
                'cep' => '01310-100',
                'logradouro' => 'Avenida Paulista',
                'bairro' => 'Bela Vista',
                'localidade' => 'São Paulo',
                'uf' => 'SP',
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/cep/01310-100');

        $response->assertStatus(200)
            ->assertJsonPath('logradouro', 'Avenida Paulista');
    }

    public function test_rejects_cep_shorter_than_eight_digits(): void
    {
        $response = $this->getJson('/api/v1/cep/12345');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'CEP inválido. O CEP deve conter exatamente 8 dígitos numéricos.');
    }

    public function test_rejects_cep_longer_than_eight_digits(): void
    {
        $response = $this->getJson('/api/v1/cep/1234567890');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'CEP inválido. O CEP deve conter exatamente 8 dígitos numéricos.');
    }

    public function test_returns_404_when_cep_not_found_in_viacep(): void
    {
        Http::fake([
            'https://viacep.com.br/ws/99999999/json/' => Http::response([
                'erro' => true,
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/cep/99999999');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'CEP não encontrado na base dos Correios.');
    }

    public function test_caches_successful_cep_query_for_future_requests(): void
    {
        Http::fake([
            'https://viacep.com.br/ws/01310100/json/' => Http::response([
                'cep' => '01310-100',
                'logradouro' => 'Avenida Paulista',
                'bairro' => 'Bela Vista',
                'localidade' => 'São Paulo',
                'uf' => 'SP',
            ], 200),
        ]);

        // Primeira chamada busca no ViaCEP e armazena em cache
        $first = $this->getJson('/api/v1/cep/01310100');
        $first->assertStatus(200);

        // Limpa o fake do Http para provar que a segunda chamada usa apenas o Cache
        Http::fake([
            'https://viacep.com.br/ws/01310100/json/' => Http::response(null, 500),
        ]);

        $second = $this->getJson('/api/v1/cep/01310100');
        $second->assertStatus(200)
            ->assertJsonPath('logradouro', 'Avenida Paulista');
    }

    public function test_returns_503_when_external_viacep_fails(): void
    {
        Http::fake([
            'https://viacep.com.br/ws/01310100/json/' => function () {
                throw new \Exception('Conexão perdida');
            },
        ]);

        $response = $this->getJson('/api/v1/cep/01310100');

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Serviço de consulta de CEP temporariamente indisponível.');
    }

    public function test_cep_endpoint_is_rate_limited(): void
    {
        Http::fake([
            'https://viacep.com.br/ws/*' => Http::response([
                'cep' => '01310-100',
                'logradouro' => 'Avenida Paulista',
                'bairro' => 'Bela Vista',
                'localidade' => 'São Paulo',
                'uf' => 'SP',
            ], 200),
        ]);

        // O throttle é de 30 req/min
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/cep/01310100');
        }

        // A 31ª requisição deve ser bloqueada
        $response = $this->getJson('/api/v1/cep/01310100');
        $response->assertStatus(429);
    }
}
