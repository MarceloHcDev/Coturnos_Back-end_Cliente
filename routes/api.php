<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FreteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ─────────────────────────────────────────────────────────────────────
    // Autenticação & Gestão de Acesso (Sanctum)
    // throttle:register → máx 10 tentativas/min por IP (Anti-DoS / Anti-bot)
    // throttle:login    → máx 6 tentativas/min por IP (Anti-força-bruta)
    // Ajuste fino (por IP+email, Redis) será feito na Semana 4 (Tarefa 4.3).
    // ─────────────────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:10,1');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:6,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);

            // Revoga TODOS os tokens ativos do cliente (sessão comprometida / troca de senha)
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        });

        // ─────────────────────────────────────────────────────────────────
        // Login Social (Google, Facebook, Apple) — Socialite (Semana 2)
        // throttle:10,1 no callback (proteção contra abuso de autenticação)
        // ─────────────────────────────────────────────────────────────────
        Route::get('/{provider}/redirect', [\App\Http\Controllers\AuthSocialController::class, 'redirect'])
            ->middleware('throttle:20,1');

        Route::get('/{provider}/callback', [\App\Http\Controllers\AuthSocialController::class, 'callback'])
            ->middleware('throttle:10,1');
        Route::post('/{provider}/confirm-link', [\App\Http\Controllers\AuthSocialController::class, 'confirmLink'])
            ->middleware('throttle:6,1');
    });

    // ─────────────────────────────────────────────────────────────────────
    // Logística / Frete
    // throttle:frete → máx 30 req/min por IP.
    // Protege contra abuso de cota da API SuperFrete (custo financeiro/contratual).
    // Cache por CEP+dimensões será implementado na Semana 3 (Módulo de Pedidos).
    // ─────────────────────────────────────────────────────────────────────
    Route::post('/frete', [FreteController::class, 'calcular'])
        ->middleware('throttle:30,1');
});