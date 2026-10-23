<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelExpiredOrders extends Command
{
    /**
     * Quando executada, busca no banco os pedidos com status pending mais antigos que o limite,
     * aplica um bloqueio de linha no banco (lockForUpdate) para evitar concorrência com o Webhook
     * e altera o status para cancelled.
     */
    protected $signature = 'orders:cancel-expired {--minutes=30 : Minutos sem pagamento até considerar o pedido expirado}';

    protected $description = 'Cancela pedidos pending sem pagamento aprovado após X minutos';

    public function handle(): int
    {
        $minutos = (int) $this->option('minutes');
        $limite = now()->subMinutes($minutos);

        $expirados = Order::where('status', 'pending')
            ->where('created_at', '<=', $limite)
            ->pluck('id');

        if ($expirados->isEmpty()) {
            $this->info('Nenhum pedido expirado.');

            return self::SUCCESS;
        }

        $canceladosTotal = 0;

        foreach ($expirados as $orderId) {
            DB::transaction(function () use ($orderId, &$canceladosTotal) {
                $order = Order::where('id', $orderId)->lockForUpdate()->first();

                if (! $order || $order->status !== 'pending') {
                    return;
                }

                $payment = $order->payment()->lockForUpdate()->first();

                if ($payment && $payment->status === 'approved') {
                    return;
                }

                $order->update(['status' => 'cancelled']);

                if ($payment && $payment->status === 'pending') {
                    $payment->update(['status' => 'cancelled']);
                }

                $canceladosTotal++;
            });
        }

        $this->info("{$canceladosTotal} pedido(s) cancelado(s) por expiração.");
        Log::info('orders:cancel-expired executado.', [
            'minutos' => $minutos,
            'cancelados' => $canceladosTotal,
        ]);

        return self::SUCCESS;
    }
}
