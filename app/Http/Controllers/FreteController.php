<?php

namespace App\Http\Controllers;

use App\Http\Requests\FreteRequest;
use App\Http\Resources\FreteResource;
use App\Services\SuperFreteService;
use Illuminate\Http\JsonResponse;

class FreteController extends Controller
{
    protected SuperFreteService $superFreteService;

    public function __construct(SuperFreteService $superFreteService)
    {
        $this->superFreteService = $superFreteService;
    }

    /**
     * Calcula as opções de frete (PAC e SEDEX) para os itens do carrinho e CEP destino informados.
     *
     * POST /api/v1/frete
     */
    public function calcular(FreteRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $options = $this->superFreteService->calcularFreteCarrinho(
                $validated['cep_destino'],
                $validated['itens']
            );

            return response()->json([
                'frete' => FreteResource::collection($options),
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Serviço de cálculo de frete temporariamente indisponível. Tente novamente mais tarde.',
            ], 503);
        }
    }
}
