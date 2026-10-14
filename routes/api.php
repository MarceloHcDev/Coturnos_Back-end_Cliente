<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthSocialController;
use App\Http\Controllers\CepController;
use App\Http\Controllers\FreteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ─────────────────────────────────────────────────────────────────────
    // Autenticação & Gestão de Acesso (Sanctum)
    // throttle:register → máx 10 tentativas/min por IP (Anti-DoS / Anti-bot)
    // throttle:login    → máx 5 tentativas/min por IP+e-mail (Anti-força-bruta)
    //                     Definido em AppServiceProvider::configureRateLimiting()
    // ─────────────────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:10,1');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

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
        Route::get('/{provider}/redirect', [AuthSocialController::class, 'redirect'])
            ->middleware('throttle:20,1');

        Route::get('/{provider}/callback', [AuthSocialController::class, 'callback'])
            ->middleware('throttle:10,1');
        Route::post('/{provider}/confirm-link', [AuthSocialController::class, 'confirmLink'])
            ->middleware('throttle:6,1');
    });

    // ─────────────────────────────────────────────────────────────────────
    // Logística / CEP & Frete (Semana 3)
    // throttle: 30 req/min por IP (protege cota das APIs ViaCEP e SuperFrete)
    // ─────────────────────────────────────────────────────────────────────
    Route::get('/cep/{cep}', [CepController::class, 'consultar'])
        ->middleware('throttle:30,1');

    Route::post('/frete', [FreteController::class, 'calcular'])
        ->middleware('throttle:30,1');
});
