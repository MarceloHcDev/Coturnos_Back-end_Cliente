<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class MercadoPagoWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $webhookSecret = 'test_webhook_secret_key_123';

    protected User $user;

    protected Order $order;

    protected Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.mercado_pago.webhook_secret', $this->webhookSecret);

        $this->user = User::create([
            'first_name' => 'Comprador',
            'last_name' => 'Militar',
            'cpf' => '111.222.333-44',
            'email' => 'comprador@exemplo.com',
            'phone' => '(11) 99999-8888',
            'password' => 'SenhaForte123!',
            'role' => 'customer',
            'active' => true,
        ]);

        $address = $this->user->addresses()->create([
            'street' => 'Rua Militar',
            'number' => '456',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01001000',
            'is_default' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->user->id,
            'address_id' => $address->id,
            'status' => 'pending',
            'subtotal' => 300.00,
            'shipping_fee' => 20.00,
            'total_amount' => 320.00,
            'shipping_method' => 'SEDEX',
        ]);

        $this->payment = Payment::create([
            'order_id' => $this->order->id,
            'gateway' => 'mercadopago',
            'preference_id' => 'pref-12345',
            'status' => 'pending',
            'amount' => 320.00,
        ]);
    }

    protected function generateSignatureHeaders(string $dataId, string $requestId, string $ts): array
    {
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $hash = hash_hmac('sha256', $manifest, $this->webhookSecret);

        return [
            'x-signature' => "ts={$ts},v1={$hash}",
            'x-request-id' => $requestId,
        ];
    }

    public function test_webhook_rejects_unauthorized_signature(): void
    {
        $response = $this->postJson('/api/v1/webhooks/mercadopago?type=payment&data.id=999999', [], [
            'x-signature' => 'ts=1234567890,v1=invalid_hash',
            'x-request-id' => 'req-1',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Assinatura inválida.']);
    }

    public function test_webhook_ignores_non_payment_event(): void
    {
        $dataId = '123456';
        $requestId = 'req-abc';
        $ts = (string) time();

        $headers = $this->generateSignatureHeaders($dataId, $requestId, $ts);

        $response = $this->postJson("/api/v1/webhooks/mercadopago?type=plan&data.id={$dataId}", [], $headers);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Ignorado.']);
    }

    public function test_webhook_processes_approved_payment_and_updates_order(): void
    {
        $paymentId = 'pay-987654';
        $requestId = 'req-xyz-123';
        $ts = (string) time();

        $headers = $this->generateSignatureHeaders($paymentId, $requestId, $ts);

        $mockMp = Mockery::mock(MercadoPagoService::class);
        $mockMp->shouldReceive('buscarPagamento')
            ->once()
            ->with($paymentId)
            ->andReturn([
                'status' => 'approved',
                'external_reference' => $this->order->order_number,
                'payment_type_id' => 'pix',
                'qr_code' => '00020126580014br.gov.bcb.pix...',
                'qr_code_base64' => 'iVBORw0KGgoAAAANSUhEUgAA...',
            ]);
        $this->app->instance(MercadoPagoService::class, $mockMp);

        $response = $this->postJson("/api/v1/webhooks/mercadopago?type=payment&data.id={$paymentId}", [], $headers);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Processado.']);

        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $this->order->id,
            'gateway_payment_id' => $paymentId,
            'method' => 'pix',
            'status' => 'approved',
            'qr_code_pix' => '00020126580014br.gov.bcb.pix...',
        ]);
    }

    public function test_webhook_cancels_order_when_payment_is_rejected(): void
    {
        $paymentId = 'pay-failed-111';
        $requestId = 'req-failed-222';
        $ts = (string) time();

        $headers = $this->generateSignatureHeaders($paymentId, $requestId, $ts);

        $mockMp = Mockery::mock(MercadoPagoService::class);
        $mockMp->shouldReceive('buscarPagamento')
            ->once()
            ->with($paymentId)
            ->andReturn([
                'status' => 'rejected',
                'external_reference' => $this->order->order_number,
                'payment_type_id' => 'credit_card',
                'qr_code' => null,
                'qr_code_base64' => null,
            ]);
        $this->app->instance(MercadoPagoService::class, $mockMp);

        $response = $this->postJson("/api/v1/webhooks/mercadopago?type=payment&data.id={$paymentId}", [], $headers);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Processado.']);

        $this->assertDatabaseHas('orders', [
            'id' => $this->order->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $this->order->id,
            'gateway_payment_id' => $paymentId,
            'status' => 'rejected',
        ]);
    }
}
