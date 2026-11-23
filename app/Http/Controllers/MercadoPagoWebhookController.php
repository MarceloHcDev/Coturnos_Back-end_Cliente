<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(protected MercadoPagoService $mercadoPago) {}

    /**
     * POST /webhooks/mercadopago
     */
    public function handle(Request $request): JsonResponse
    {
        if (! $this->assinaturaValida($request)) {
            Log::warning('Webhook Mercado Pago rejeitado: assinatura inválida.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Assinatura inválida.'], 401);
        }

        // Só nos interessam notificações de pagamento
        if ($request->query('type') !== 'payment') {
            return response()->json(['message' => 'Ignorado.'], 200);
        }

        $paymentId = $this->extractDataId($request);

        if (empty($paymentId)) {
            return response()->json(['message' => 'data.id ausente.'], 400);
        }

        try {
            $paymentInfo = $this->mercadoPago->buscarPagamento((string) $paymentId);
        } catch (RuntimeException $e) {
            report($e);

            // 200 para o Mercado Pago não continuar reenviando uma notificação que falhará
            return response()->json(['message' => 'Falha ao consultar pagamento.'], 200);
        }

        $orderNumber = $paymentInfo['external_reference'] ?? null;

        if (empty($orderNumber)) {
            Log::warning('Webhook Mercado Pago: pagamento sem external_reference.', ['payment_id' => $paymentId]);

            return response()->json(['message' => 'Pedido não identificado.'], 200);
        }

        DB::transaction(function () use ($paymentInfo, $orderNumber, $paymentId) {
            // lockForUpdate: impede concorrência de notificações duplicadas em paralelo
            $order = Order::where('order_number', $orderNumber)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                Log::warning('Webhook Mercado Pago: pedido não encontrado para o external_reference.', [
                    'order_number' => $orderNumber,
                    'payment_id' => $paymentId,
                ]);

                return;
            }

            $payment = $order->payment()->lockForUpdate()->first();

            if (! $payment) {
                Log::warning('Webhook Mercado Pago: payment não encontrado para o pedido.', [
                    'order_number' => $orderNumber,
                    'payment_id' => $paymentId,
                ]);

                return;
            }

            $payment->update([
                'gateway_payment_id' => $paymentId,
                'method' => $paymentInfo['payment_type_id'] ?? $payment->method,
                'status' => $paymentInfo['status'],
                'qr_code_pix' => $paymentInfo['qr_code'] ?? $payment->qr_code_pix,
                'qr_code_base64' => $paymentInfo['qr_code_base64'] ?? $payment->qr_code_base64,
                'response_payload' => $paymentInfo,
            ]);

            if ($order && $paymentInfo['status'] === 'approved' && $order->status === 'pending') {
                $order->update(['status' => 'paid']);
            }

            if ($order && in_array($paymentInfo['status'], ['rejected', 'cancelled'], true) && $order->status === 'pending') {
                $order->update(['status' => 'cancelled']);
            }
        });

        return response()->json(['message' => 'Processado.'], 200);
    }

    /**
     * Valida a assinatura HMAC-SHA256 enviada pelo Mercado Pago no header
     * "x-signature" (formato: "ts=...,v1=...").
     *
     * Manifest oficial: "id:{data.id};request-id:{x-request-id};ts:{ts};"
     */
    protected function assinaturaValida(Request $request): bool
    {
        $secret = config('services.mercado_pago.webhook_secret');

        if (empty($secret)) {
            Log::error('Webhook Mercado Pago: mercadopago_webhook_secret não configurado.');

            return false;
        }

        $signatureHeader = $request->header('x-signature');
        $requestId = $request->header('x-request-id');
        $dataId = $this->extractDataId($request);

        if (empty($signatureHeader) || empty($requestId) || empty($dataId)) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            $parts[$key] = $value;
        }

        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;

        if (empty($ts) || empty($v1)) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $hash = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($hash, $v1);
    }

    /**
     * Extrai o data.id da requisição, tratando tanto o formato padrão quanto
     * a conversão automática de pontos para sublinhados feita pelo PHP em query strings.
     */
    protected function extractDataId(Request $request): ?string
    {
        $id = $request->query('data_id')
            ?? $request->query('data.id')
            ?? data_get($request->query('data'), 'id')
            ?? $request->input('data.id')
            ?? $request->input('id');

        return ! empty($id) ? (string) $id : null;
    }
}
