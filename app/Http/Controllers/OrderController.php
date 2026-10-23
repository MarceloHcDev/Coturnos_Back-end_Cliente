<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Product;
use App\Services\SuperFreteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(protected SuperFreteService $superFrete) {}

    /**
     * Lista os pedidos do usuário autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with(['orderItems.product', 'payment', 'address'])
            ->latest()
            ->paginate(15);

        return response()->json($orders);
    }

    /**
     * Exibe os detalhes de um pedido específico do usuário autenticado (Anti-IDOR).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = $request->user()
            ->orders()
            ->with(['orderItems.product', 'payment', 'address'])
            ->findOrFail($id);

        return response()->json($order);
    }

    /**
     * Cria um novo pedido para o usuário autenticado.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $order = DB::transaction(function () use ($data, $request) {
            // Anti-IDOR: Garante que o endereço pertence estritamente ao usuário autenticado
            $address = $request->user()
                ->addresses()
                ->findOrFail($data['address_id']);

            $productIds = collect($data['items'])->pluck('product_id')->unique();

            // Bloqueio pessimista para evitar concorrência no cálculo
            $products = Product::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;
            $itemsParaCriar = [];
            $itensSuperFrete = [];

            foreach ($data['items'] as $item) {
                $product = $products->get($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => "Produto {$item['product_id']} não encontrado.",
                    ]);
                }

                $unitPrice = (float) ($product->promotional_price ?? $product->base_price);
                $itemSubtotal = round($unitPrice * $item['quantity'], 2);
                $subtotal += $itemSubtotal;

                $itemsParaCriar[] = [
                    'product_id' => $product->id,
                    'size' => $item['size'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                ];

                $itensSuperFrete[] = [
                    'produto_id' => $product->id,
                    'quantidade' => (int) $item['quantity'],
                ];
            }

            // Cálculo do frete via SuperFreteService agregado
            try {
                $opcoesFrete = $this->superFrete->calcularFreteCarrinho(
                    cepDestino: $address->zip_code,
                    itens: $itensSuperFrete
                );
            } catch (\Throwable $e) {
                report($e);

                throw ValidationException::withMessages([
                    'shipping' => 'Não foi possível calcular o frete para o endereço informado.',
                ]);
            }

            $serviceRequested = (string) $data['shipping_service_id'];
            $opcaoEscolhida = collect($opcoesFrete)->first(function ($opcao) use ($serviceRequested) {
                return (string) ($opcao['codigo'] ?? '') === $serviceRequested
                    || strtoupper((string) ($opcao['servico'] ?? '')) === strtoupper($serviceRequested);
            });

            if (! $opcaoEscolhida || empty($opcaoEscolhida['disponivel'])) {
                throw ValidationException::withMessages([
                    'shipping_service_id' => 'Opção de frete indisponível para este endereço.',
                ]);
            }

            $shippingFee = (float) $opcaoEscolhida['valor'];
            $discountFee = (float) ($data['discount_fee'] ?? 0);
            $totalAmount = round($subtotal + $shippingFee - $discountFee, 2);

            $order = Order::create([
                'user_id' => $request->user()->id,
                'address_id' => $address->id,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'discount_fee' => $discountFee,
                'total_amount' => $totalAmount,
                'shipping_method' => $opcaoEscolhida['servico'],
            ]);

            $order->orderItems()->createMany($itemsParaCriar);

            return $order;
        });

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'subtotal' => $order->subtotal,
            'shipping_fee' => $order->shipping_fee,
            'discount_fee' => $order->discount_fee,
            'total_amount' => $order->total_amount,
            'items' => $order->orderItems()->with('product')->get(),
        ], 201);
    }
}
