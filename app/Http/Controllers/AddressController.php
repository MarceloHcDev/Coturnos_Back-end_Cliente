<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnderecoRequest;
use App\Http\Requests\UpdateEnderecoRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    /**
     * Lista todos os endereços do usuário autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()
            ->addresses()
            ->orderByDesc('is_default')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => AddressResource::collection($addresses),
        ], 200);
    }

    /**
     * Cria um novo endereço vinculado estritamente ao usuário autenticado.
     *
     * Anti-IDOR: O endereço é instanciado através do relacionamento $user->addresses()->create().
     * Qualquer tentativa de enviar 'user_id' ou 'usuario_id' é ignorada.
     */
    public function store(StoreEnderecoRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $address = DB::transaction(function () use ($request, $validated) {
            $user = $request->user();

            // Se for o primeiro endereço do usuário, define como padrão automaticamente
            if (! isset($validated['is_default'])) {
                $hasExisting = $user->addresses()->exists();
                if (! $hasExisting) {
                    $validated['is_default'] = true;
                }
            }

            return $user->addresses()->create($validated);
        });

        return response()->json([
            'message' => 'Endereço cadastrado com sucesso.',
            'data' => new AddressResource($address),
        ], 201);
    }

    /**
     * Exibe os detalhes de um endereço específico do usuário autenticado.
     *
     * Anti-IDOR: Busca estritamente dentro do relacionamento $request->user()->addresses().
     * Se o ID pertencer a outro usuário ou não existir, o findOrFail() devolve 404
     * (não revelando que o recurso de outro cliente existe).
     */
    public function show(Request $request, int|string $id): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);

        return response()->json([
            'data' => new AddressResource($address),
        ], 200);
    }

    /**
     * Atualiza dados de um endereço do usuário autenticado.
     *
     * Anti-IDOR: Escopado em $request->user()->addresses()->findOrFail($id).
     */
    public function update(UpdateEnderecoRequest $request, int|string $id): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);

        DB::transaction(function () use ($address, $request) {
            $address->update($request->validated());
        });

        return response()->json([
            'message' => 'Endereço atualizado com sucesso.',
            'data' => new AddressResource($address->fresh()),
        ], 200);
    }

    /**
     * Exclui (soft delete) um endereço do usuário autenticado.
     *
     * Soft delete preserva a integridade de pedidos já realizados com este endereço.
     * Anti-IDOR: findOrFail escopado no relacionamento do usuário (404 em ID alheio).
     */
    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);

        DB::transaction(function () use ($address, $request) {
            $wasDefault = $address->is_default;
            $address->delete();

            // Se o endereço excluído era o padrão, elege o mais recente como novo padrão (se houver)
            if ($wasDefault) {
                $nextDefault = $request->user()->addresses()->latest('id')->first();
                if ($nextDefault) {
                    $nextDefault->update(['is_default' => true]);
                }
            }
        });

        return response()->json([
            'message' => 'Endereço removido com sucesso.',
        ], 200);
    }

    /**
     * Define um endereço específico como o padrão do usuário autenticado.
     *
     * Regra: Atualiza dentro de DB::transaction, o evento booted() da model Address
     * garante desmarcar os outros endereços do mesmo usuário.
     */
    public function setDefault(Request $request, int|string $id): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);

        DB::transaction(function () use ($address) {
            $address->update(['is_default' => true]);
        });

        return response()->json([
            'message' => 'Endereço definido como padrão com sucesso.',
            'data' => new AddressResource($address->fresh()),
        ], 200);
    }
}
