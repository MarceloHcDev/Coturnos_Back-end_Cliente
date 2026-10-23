<?php

namespace App\Services;

use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RuntimeException;

class MercadoPagoService
{
    protected ?PreferenceClient $preferenceClient = null;

    protected ?PaymentClient $paymentClient = null;

    public function __construct()
    {
        $accessToken = config('services.mercado_pago.access_token');

        if (! empty($accessToken)) {
            MercadoPagoConfig::setAccessToken($accessToken);
            $this->preferenceClient = new PreferenceClient;
            $this->paymentClient = new PaymentClient;
        }
    }

    protected function ensureClientsInitialized(): void
    {
        if ($this->preferenceClient === null || $this->paymentClient === null) {
            $accessToken = config('services.mercado_pago.access_token');

            if (empty($accessToken)) {
                throw new RuntimeException('Token de acesso do Mercado Pago não configurado.');
            }

            MercadoPagoConfig::setAccessToken($accessToken);
            $this->preferenceClient = new PreferenceClient;
            $this->paymentClient = new PaymentClient;
        }
    }

    /**
     * Cria uma preferência de pagamento no Mercado Pago.
     *
     * @param  array  $data  Payload da preferência (items, payer, back_urls, etc.)
     * @return array{id: string, init_point: string}
     *
     * @throws RuntimeException
     */
    public function criarPreferencia(array $data): array
    {
        $this->ensureClientsInitialized();

        try {
            $preference = $this->preferenceClient->create($data);
        } catch (MPApiException $e) {
            throw new RuntimeException(
                'Erro ao criar preferência no Mercado Pago: '.json_encode($e->getApiResponse()->getContent()),
                $e->getApiResponse()->getStatusCode(),
                $e
            );
        }

        return [
            'id' => $preference->id,
            'init_point' => $preference->init_point,
        ];
    }

    /**
     * Consulta um pagamento pelo id, usado pelo webhook para confirmar
     * status, preference_id e dados de PIX antes de atualizar o pedido.
     *
     * @return array{status: string, external_reference: ?string, payment_type_id: ?string, qr_code: ?string, qr_code_base64: ?string}
     *
     * @throws RuntimeException
     */
    public function buscarPagamento(string $paymentId): array
    {
        $this->ensureClientsInitialized();

        try {
            $payment = $this->paymentClient->get($paymentId);
        } catch (MPApiException $e) {
            throw new RuntimeException(
                'Erro ao consultar pagamento no Mercado Pago: '.json_encode($e->getApiResponse()->getContent()),
                $e->getApiResponse()->getStatusCode(),
                $e
            );
        }

        $pointOfInteraction = $payment->point_of_interaction->transaction_data ?? null;

        return [
            'status' => $payment->status,
            'external_reference' => $payment->external_reference ?? null,
            'payment_type_id' => $payment->payment_type_id ?? null,
            'qr_code' => $pointOfInteraction->qr_code ?? null,
            'qr_code_base64' => $pointOfInteraction->qr_code_base64 ?? null,
        ];
    }
}
