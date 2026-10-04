<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Registra um novo cliente e emite token Sanctum.
     *
     * POST /api/v1/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $userData = $request->safe()->only([
            'first_name',
            'last_name',
            'cpf',
            'email',
            'phone',
            'birth_date',
            'password',
        ]);

        $userData['last_name'] = $userData['last_name'] ?? '';

        $user = User::create($userData);

        if ($request->filled('zip') || $request->filled('street')) {
            $user->addresses()->create([
                'zip_code'     => preg_replace('/\D/', '', (string) ($request->validated('zip') ?? '')),
                'street'       => (string) ($request->validated('street') ?? ''),
                'number'       => (string) ($request->validated('number') ?? ''),
                'complement'   => $request->validated('complement'),
                'neighborhood' => (string) ($request->validated('neighborhood') ?? ''),
                'city'         => (string) ($request->validated('city') ?? ''),
                'state'        => (string) ($request->validated('state') ?? ''),
                'is_default'   => true,
            ]);
        }

        $token = $user->createToken('auth-token', ['*'], now()->addDays(7))->plainTextToken;

        return response()->json([
            'message' => 'Cadastro realizado com sucesso.',
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Autentica o cliente e emite token Sanctum.
     *
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        // Proteção contra Timing Attack (User Enumeration):
        // Se o usuário não existir, roda um hash dummy para manter o tempo de resposta constante.
        $dummyHash = '$2y$12$e8eO7.0gOom4zKj3QZ7V.uO1dKkWjB.e8eO7.0gOom4zKj3QZ7V.u';
        $passwordValid = Hash::check($request->password, $user?->password ?? $dummyHash);

        if (! $user || ! $passwordValid) {
            return response()->json([
                'message' => 'Credenciais inválidas.',
            ], 401);
        }

        if (! $user->active) {
            return response()->json([
                'message' => 'Esta conta está inativa. Entre em contato com o suporte.',
            ], 403);
        }

        $token = $user->createToken('auth-token', ['*'], now()->addDays(7))->plainTextToken;

        return response()->json([
            'message' => 'Login realizado com sucesso.',
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * Retorna os dados do cliente autenticado.
     *
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    /**
     * Revoga o token atual do cliente (Logout).
     *
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout realizado com sucesso.',
        ]);
    }

    /**
     * Revoga TODOS os tokens ativos do cliente (Logout de todos os dispositivos).
     *
     * Indicado em caso de token vazado, troca de senha ou acesso suspeito.
     * Após este endpoint, o cliente precisará fazer login novamente em todos os dispositivos.
     *
     * POST /api/v1/auth/logout-all
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Todas as sessões foram encerradas com sucesso.',
        ]);
    }
}
