<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Services\SuperFreteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Address $address;

    protected Product $product;

    protected function createUser(string $email = 'cliente@exemplo.com'): User
    {
        static $counter = 100;
        $counter++;

        return User::create([
            'first_name' => 'Cliente',
            'last_name' => 'Teste',
            'cpf' => sprintf('123.456.%03d-%02d', $counter % 1000, $counter % 100),
            'email' => $email,
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
            'role' => 'customer',
            'active' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();

        $this->address = $this->user->addresses()->create([
            'street' => 'Rua das Flores',
            'number' => '123',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01001000',
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Coturno Tático Defender',
            'slug' => 'coturno-tatico-defender',
            'product_type' => 'Coturno Militar',
            'brand' => 'Coturnos & Cia',
            'description' => 'Coturno resistente de couro legítimo.',
            'base_price' => 299.90,
            'promotional_price' => null,
            'active' => true,
        ]);
    }

    public function test_user_can_create_order_with_valid_items_and_shipping(): void
    {
        $mockSuperFrete = Mockery::mock(SuperFreteService::class);
        $mockSuperFrete->shouldReceive('calcularFreteCarrinho')
            ->once()
            ->andReturn([
                [
                    'servico' => 'PAC',
                    'codigo' => '1',
                    'valor' => 25.50,
                    'prazo_dias' => 5,
                    'disponivel' => true,
                ],
                [
                    'servico' => 'SEDEX',
                    'codigo' => '2',
                    'valor' => 45.00,
                    'prazo_dias' => 2,
                    'disponivel' => true,
                ],
            ]);
        $this->app->instance(SuperFreteService::class, $mockSuperFrete);

        $payload = [
            'address_id' => $this->address->id,
            'shipping_service_id' => '1',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'size' => 41,
                    'quantity' => 2,
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/pedidos', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'order_id',
                'order_number',
                'status',
                'subtotal',
                'shipping_fee',
                'total_amount',
                'items',
            ]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'address_id' => $this->address->id,
            'status' => 'pending',
            'subtotal' => 599.80,
            'shipping_fee' => 25.50,
            'total_amount' => 625.30,
        ]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $this->product->id,
            'size' => 41,
            'quantity' => 2,
            'unit_price' => 299.90,
            'subtotal' => 599.80,
        ]);
    }

    public function test_anti_idor_prevents_creating_order_with_another_users_address(): void
    {
        $anotherUser = $this->createUser('outro_user_'.uniqid().'@exemplo.com');
        $anotherAddress = $anotherUser->addresses()->create([
            'street' => 'Avenida Brasil',
            'number' => '500',
            'neighborhood' => 'Jardins',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01430000',
            'is_default' => true,
        ]);

        $payload = [
            'address_id' => $anotherAddress->id,
            'shipping_service_id' => '1',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'size' => 40,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/pedidos', $payload);

        $response->assertStatus(404);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_user_can_list_only_their_own_orders(): void
    {
        $anotherUser = $this->createUser('outro_user_'.uniqid().'@exemplo.com');

        Order::create([
            'user_id' => $this->user->id,
            'address_id' => $this->address->id,
            'status' => 'pending',
            'subtotal' => 100.00,
            'shipping_fee' => 20.00,
            'total_amount' => 120.00,
            'shipping_method' => 'PAC',
        ]);

        Order::create([
            'user_id' => $anotherUser->id,
            'address_id' => null,
            'status' => 'pending',
            'subtotal' => 200.00,
            'shipping_fee' => 20.00,
            'total_amount' => 220.00,
            'shipping_method' => 'PAC',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/pedidos');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->user->id, $data[0]['user_id']);
    }

    public function test_anti_idor_prevents_viewing_another_users_order(): void
    {
        $anotherUser = $this->createUser('outro_user_'.uniqid().'@exemplo.com');
        $foreignOrder = Order::create([
            'user_id' => $anotherUser->id,
            'address_id' => null,
            'status' => 'pending',
            'subtotal' => 200.00,
            'shipping_fee' => 20.00,
            'total_amount' => 220.00,
            'shipping_method' => 'PAC',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/pedidos/{$foreignOrder->id}");

        $response->assertStatus(404);
    }

    public function test_user_can_create_checkout_preference_for_pending_order(): void
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'address_id' => $this->address->id,
            'status' => 'pending',
            'subtotal' => 299.90,
            'shipping_fee' => 25.00,
            'total_amount' => 324.90,
            'shipping_method' => 'PAC',
        ]);

        $mockMp = Mockery::mock(MercadoPagoService::class);
        $mockMp->shouldReceive('criarPreferencia')
            ->once()
            ->with(Mockery::on(function ($payload) use ($order) {
                return $payload['external_reference'] === $order->order_number
                    && $payload['payer']['email'] === $this->user->email;
            }))
            ->andReturn([
                'id' => 'pref-mock-12345',
                'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-mock-12345',
            ]);
        $this->app->instance(MercadoPagoService::class, $mockMp);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/checkout/preferencia', [
                'order_id' => $order->id,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'order_id' => $order->id,
                'preference_id' => 'pref-mock-12345',
                'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-mock-12345',
            ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'preference_id' => 'pref-mock-12345',
            'status' => 'pending',
            'amount' => 324.90,
        ]);
    }

    public function test_anti_idor_prevents_generating_preference_for_another_users_order(): void
    {
        $anotherUser = $this->createUser('outro_user_'.uniqid().'@exemplo.com');
        $foreignOrder = Order::create([
            'user_id' => $anotherUser->id,
            'address_id' => null,
            'status' => 'pending',
            'subtotal' => 100.00,
            'shipping_fee' => 20.00,
            'total_amount' => 120.00,
            'shipping_method' => 'PAC',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/checkout/preferencia', [
                'order_id' => $foreignOrder->id,
            ]);

        $response->assertStatus(404);
    }
}
