<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FreteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Autenticação & Gestão de Acesso (Sanctum)
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    // Logística / Frete
    Route::post('/frete', [FreteController::class, 'calcular']);
});

// Retrocompatibilidade sem prefixo de versão
Route::post('/frete', [FreteController::class, 'calcular']);