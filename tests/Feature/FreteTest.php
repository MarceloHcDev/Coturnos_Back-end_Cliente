<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FreteTest extends TestCase
{
    use RefreshDatabase;

    protected Product $produto1;

    protected Product $produto2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produto1 = Product::create([
            'name' => 'Coturno Tático T-800',
            'slug' => 'coturno-tatico-t-800',
            'product_type' => 'Coturno Tático',
            'brand' => 'Coturnos & Cia',
            'description' => 'Coturno em couro legítimo impermeável.',
            'base_price' => 389.90,
            'active' => true,
        ]);

        $this->produto2 = Product::create([
            'name' => 'Coturno Militar Falcon',
            'slug' => 'coturno-militar-falcon',
            'product_type' => 'Coturno Militar',
            'brand' => 'Coturnos & Cia',
            'description' => 'Solado vibram e palmilha anatômica.',
            'base_price' => 459.90,
            'active' => true,
        ]);
    }

    public function test_calculates_frete_for_single_item_successfully(): void
    {
        Http::fake([
            'https://sandbox.superfrete.com/api/v0/calculator' => Http::response([
                [
                    'id' => 1,
                    'name' => 'PAC',
                    'price' => '23.90',
                    'delivery_time' => 5,
                    'error' => null,
                ],
                [
                    'id' => 2,
                    'name' => 'SEDEX',
                    'price' => '41.50',
                    'delivery_time' => 2,
                    'error' => null,
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/frete', [
            'cep_destino' => '01310-100',
            'itens' => [
                ['produto_id' => $this->produto1->id, 'quantidade' => 1],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'frete' => [
                    '*' => ['servico', 'codigo', 'valor', 'prazo_dias', 'disponivel'],
                ],
            ])
            ->assertJsonPath('frete.0.servico', 'PAC')
            ->assertJsonPath('frete.0.valor', 23.90)
            ->assertJsonPath('frete.0.prazo_dias', 5)
            ->assertJsonPath('frete.0.disponivel', true)
            ->assertJsonPath('frete.1.servico', 'SEDEX')
            ->assertJsonPath('frete.1.valor', 41.50)
            ->assertJsonPath('frete.1.prazo_dias', 2)
            ->assertJsonPath('frete.1.disponivel', true);

        // Garante que o valor é tipado estritamente como float e o prazo como int
        $data = $response->json();
        $this->assertIsFloat($data['frete'][0]['valor']);
        $this->assertIsInt($data['frete'][0]['prazo_dias']);
    }

    public function test_aggregates_dimensions_and_weight_for_multiple_items(): void
    {
        Http::fake([
            'https://sandbox.superfrete.com/api/v0/calculator' => function ($request) {
                $payload = $request->data();

                // 2 itens produto1 + 1 item produto2 = 3 coturnos
                // Peso: 3 * 1.5kg = 4.50kg
                // Altura: 3 * 14cm = 42.00cm
                $package = $payload['package'];
                if ($package['weight'] === '4.50' && $package['height'] === '42.00') {
                    return Http::response([
                        [
                            'id' => 1,
                            'name' => 'PAC',
                            'price' => '35.40',
                            'delivery_time' => 6,
                            'error' => null,
                        ],
                    ], 200);
                }

                return Http::response(['message' => 'Medidas incorretas'], 400);
            },
        ]);

        $response = $this->postJson('/api/v1/frete', [
            'cep_destino' => '01310100',
            'itens' => [
                ['produto_id' => $this->produto1->id, 'quantidade' => 2],
                ['produto_id' => $this->produto2->id, 'quantidade' => 1],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('frete.0.valor', 35.40);
    }

    public function test_rejects_payload_without_items(): void
    {
        $response = $this->postJson('/api/v1/frete', [
            'cep_destino' => '01310100',
            'itens' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['itens']);
    }

    public function test_rejects_invalid_cep_format(): void
    {
        $response = $this->postJson('/api/v1/frete', [
            'cep_destino' => '123',
            'itens' => [
                ['produto_id' => $this->produto1->id, 'quantidade' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cep_destino']);
    }

    public function test_rejects_quantity_exceeding_anti_dos_ceiling(): void
    {
        $response = $this->postJson('/api/v1/frete', [
            'cep_destino' => '01310100',
            'itens' => [
                ['produto_id' => $this->produto1->id, 'quantidade' => 51],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['itens.0.quantidade']);

        $errors = $response->json('errors');
        $this->assertEquals(
            'A quantidade máxima permitida por item para cálculo direto é de 50 unidades.',
            $errors['itens.0.quantidade'][0]
        );
    }

    public function test_rejects_product_not_existing_in_catalog(): void
    {
        $response = $this->postJson('/api/v1/frete', [
            'cep_destino' => '01310100',
            'itens' => [
                ['produto_id' => 999999, 'quantidade' => 1],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['itens.0.produto_id']);
    }

    public function test_handles_service_unavailable_by_carrier_with_disponivel_false(): void
    {
        Http::fake([
            'https://sandbox.superfrete.com/api/v0/calculator' => Http::response([
                [
                    'id' => 1,
                    'name' => 'PAC',
                    'price' => '22.00',
                    'delivery_time' => 7,
                    'error' => null,
                ],
                [
                    'id' => 2,
                    'name' => 'SEDEX',
                    'price' => null,
                    'delivery_time' => null,
                    'error' => 'Serviço indisponível para o trecho informado',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/frete', [
            'cep_destino' => '01310100',
            'itens' => [
                ['produto_id' => $this->produto1->id, 'quantidade' => 1],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('frete.0.servico', 'PAC')
            ->assertJsonPath('frete.0.disponivel', true)
            ->assertJsonPath('frete.1.servico', 'SEDEX')
            ->assertJsonPath('frete.1.disponivel', false);

        $this->assertEquals(0.0, (float) $response->json('frete.1.valor'));
    }

    public function test_returns_503_when_superfrete_fails_without_leaking_credentials(): void
    {
        Http::fake([
            'https://sandbox.superfrete.com/api/v0/calculator' => Http::response(null, 500),
        ]);

        $response = $this->postJson('/api/v1/frete', [
            'cep_destino' => '01310100',
            'itens' => [
                ['produto_id' => $this->produto1->id, 'quantidade' => 1],
            ],
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('message', 'Serviço de cálculo de frete temporariamente indisponível. Tente novamente mais tarde.');
    }

    public function test_frete_endpoint_is_rate_limited(): void
    {
        Http::fake([
            'https://sandbox.superfrete.com/api/v0/calculator' => Http::response([
                [
                    'id' => 1,
                    'name' => 'PAC',
                    'price' => '20.00',
                    'delivery_time' => 5,
                    'error' => null,
                ],
            ], 200),
        ]);

        // Rate limit de 30 req/min
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/frete', [
                'cep_destino' => '01310100',
                'itens' => [
                    ['produto_id' => $this->produto1->id, 'quantidade' => 1],
                ],
            ]);
        }

        // A 31ª requisição deve retornar 429
        $response = $this->postJson('/api/v1/frete', [
            'cep_destino' => '01310100',
            'itens' => [
                ['produto_id' => $this->produto1->id, 'quantidade' => 1],
            ],
        ]);

        $response->assertStatus(429);
    }
}
