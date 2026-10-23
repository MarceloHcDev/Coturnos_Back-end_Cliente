<?php

namespace App\Http\Controllers;

use App\Services\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(protected MercadoPagoService $mercadoPago) {}

    /**
     * POST /checkout/preferencia
     * Gera a preferência de pagamento no Mercado Pago vinculada ao pedido.
     */
    public function preferencia(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'back_urls.success' => ['sometimes', 'url'],
            'back_urls.failure' => ['sometimes', 'url'],
            'back_urls.pending' => ['sometimes', 'url'],
        ]);

        // Anti-IDOR: Garante que o pedido pertence estritamente ao usuário autenticado
        $order = $request->user()
            ->orders()
            ->findOrFail($validated['order_id']);

        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'Apenas pedidos pendentes podem gerar um pagamento.',
            ], 422);
        }

        // O valor vem do pedido gravado no banco de dados, nunca do request.
        $items = [[
            'title' => "Pedido {$order->order_number}",
            'quantity' => 1,
            'unit_price' => (float) $order->subtotal,
            'currency_id' => 'BRL',
        ]];

        if ((float) $order->shipping_fee > 0) {
            $items[] = [
                'title' => 'Frete',
                'quantity' => 1,
                'unit_price' => (float) $order->shipping_fee,
                'currency_id' => 'BRL',
            ];
        }

        if ((float) $order->discount_fee > 0) {
            $items[] = [
                'title' => 'Desconto',
                'quantity' => 1,
                'unit_price' => (float) $order->discount_fee * -1,
                'currency_id' => 'BRL',
            ];
        }

        $payload = [
            'items' => $items,
            'payer' => ['email' => $request->user()->email],
            'external_reference' => $order->order_number,
        ];

        if (isset($validated['back_urls'])) {
            $payload['back_urls'] = $validated['back_urls'];
        }

        try {
            $preferencia = $this->mercadoPago->criarPreferencia($payload);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'Não foi possível criar a preferência de pagamento.',
            ], 502);
        }

        $order->payment()->updateOrCreate([], [
            'gateway' => 'mercadopago',
            'preference_id' => $preferencia['id'],
            'init_point' => $preferencia['init_point'],
            'status' => 'pending',
            'amount' => $order->total_amount,
            'response_payload' => $preferencia,
        ]);

        return response()->json([
            'order_id' => $order->id,
            'preference_id' => $preferencia['id'],
            'init_point' => $preferencia['init_point'],
        ], 201);
    }
}
