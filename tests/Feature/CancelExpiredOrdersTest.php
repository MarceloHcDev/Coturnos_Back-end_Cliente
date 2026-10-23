<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelExpiredOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancels_pending_orders_older_than_threshold(): void
    {
        $user = User::create([
            'first_name' => 'Teste',
            'last_name' => 'Expiracao',
            'cpf' => '999.888.777-66',
            'email' => 'expiracao@exemplo.com',
            'phone' => '(11) 91111-2222',
            'password' => 'SenhaForte123!',
            'role' => 'customer',
            'active' => true,
        ]);

        $address = $user->addresses()->create([
            'street' => 'Rua das Flores',
            'number' => '123',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01001000',
            'is_default' => true,
        ]);

        // Pedido expirado (40 minutos atrás)
        $expiredOrder = Order::create([
            'user_id' => $user->id,
            'address_id' => $address->id,
            'status' => 'pending',
            'subtotal' => 100.00,
            'shipping_fee' => 15.00,
            'total_amount' => 115.00,
            'shipping_method' => 'PAC',
        ]);
        $expiredOrder->created_at = now()->subMinutes(40);
        $expiredOrder->save();

        Payment::create([
            'order_id' => $expiredOrder->id,
            'gateway' => 'mercadopago',
            'preference_id' => 'pref-old',
            'status' => 'pending',
            'amount' => 115.00,
        ]);

        // Pedido recente (10 minutos atrás)
        $recentOrder = Order::create([
            'user_id' => $user->id,
            'address_id' => $address->id,
            'status' => 'pending',
            'subtotal' => 200.00,
            'shipping_fee' => 20.00,
            'total_amount' => 220.00,
            'shipping_method' => 'SEDEX',
        ]);
        $recentOrder->created_at = now()->subMinutes(10);
        $recentOrder->save();

        $this->artisan('orders:cancel-expired --minutes=30')
            ->expectsOutput('1 pedido(s) cancelado(s) por expiração.')
            ->assertExitCode(0);

        $this->assertEquals('cancelled', $expiredOrder->fresh()->status);
        $this->assertEquals('cancelled', $expiredOrder->fresh()->payment->status);
        $this->assertEquals('pending', $recentOrder->fresh()->status);
    }
}
