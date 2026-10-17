<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Atualiza dados cadastrais básicos do usuário autenticado (nome, telefone).
     *
     * Prevenção de Mass Assignment: apenas first_name, last_name e phone
     * são atualizados. Qualquer campo como role, active, email, cpf é descartado.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validated();
        $fieldsToUpdate = [];

        if (array_key_exists('first_name', $validated)) {
            $fieldsToUpdate['first_name'] = $validated['first_name'];
        }

        if (array_key_exists('last_name', $validated)) {
            $fieldsToUpdate['last_name'] = $validated['last_name'];
        }

        if (array_key_exists('phone', $validated)) {
            $fieldsToUpdate['phone'] = $validated['phone'];
        }

        $user->update($fieldsToUpdate);

        return response()->json([
            'message' => 'Perfil atualizado com sucesso.',
            'user' => new UserResource($user->fresh()),
        ], 200);
    }

    /**
     * Atualiza ou define a senha do usuário autenticado.
     *
     * - Se o usuário já possuir senha, valida senha atual com Hash::check().
     * - Garante que a nova senha seja diferente da atual.
     * - O cast 'password' => 'hashed' na model User cuida do hash nativamente.
     * - Revoga todos os outros tokens do usuário, mantendo apenas a sessão atual ativa.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $newPassword = $request->input('password');

        if ($user->password !== null) {
            $currentPassword = (string) $request->input('current_password');

            if (! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['A senha atual informada está incorreta.'],
                ]);
            }

            if (Hash::check($newPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'password' => ['A nova senha deve ser diferente da senha atual.'],
                ]);
            }
        }

        // Atribui diretamente; o cast 'password' => 'hashed' da model User gera o hash
        $user->password = $newPassword;
        $user->save();

        // Revoga todos os outros tokens Sanctum, mantendo apenas o token atual
        $currentTokenId = $user->currentAccessToken()?->id;
        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        return response()->json([
            'message' => 'Senha alterada com sucesso. Outras sessões foram encerradas.',
        ], 200);
    }
}
