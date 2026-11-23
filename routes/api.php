<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthSocialController;
use App\Http\Controllers\CepController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\FreteController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CarouselController;
use App\Http\Controllers\ProductsController;
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
    // Hero do front-end do cliente (público, sem autenticação)
    // URL final: /api/v1/carousel
    // ─────────────────────────────────────────────────────────────────────
    Route::get('/carousel', [CarouselController::class, 'index']);
    Route::get('/products', [ProductsController::class, 'index']);

    // ─────────────────────────────────────────────────────────────────────
    // Perfil do Usuário e Endereços (Semana 4 — Tarefa 4.3)
    // ─────────────────────────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {
        Route::put('/perfil', [ProfileController::class, 'update'])
            ->middleware('throttle:15,1');
        Route::put('/perfil/senha', [ProfileController::class, 'updatePassword'])
            ->middleware('throttle:6,1');

        // CRUD de Endereços 100% Anti-IDOR (escopado no usuário autenticado)
        Route::prefix('enderecos')->group(function () {
            Route::get('/', [AddressController::class, 'index']);
            Route::post('/', [AddressController::class, 'store']);
            Route::get('/{id}', [AddressController::class, 'show']);
            Route::put('/{id}', [AddressController::class, 'update']);
            Route::delete('/{id}', [AddressController::class, 'destroy']);
            Route::patch('/{id}/padrao', [AddressController::class, 'setDefault']);
        });

        // ─────────────────────────────────────────────────────────────────
        // Pedidos & Checkout (Marcelo Rezende)
        // ─────────────────────────────────────────────────────────────────
        Route::prefix('pedidos')->group(function () {
            Route::get('/', [OrderController::class, 'index']);
            Route::post('/', [OrderController::class, 'store']);
            Route::get('/{id}', [OrderController::class, 'show']);
        });

        Route::post('/checkout/preferencia', [CheckoutController::class, 'preferencia'])
            ->middleware('throttle:30,1');
    });

    // ─────────────────────────────────────────────────────────────────────
    // Logística / CEP & Frete (Semana 3)
    // throttle: 30 req/min por IP (protege cota das APIs ViaCEP e SuperFrete)
    // ─────────────────────────────────────────────────────────────────────
    Route::get('/cep/{cep}', [CepController::class, 'consultar'])
        ->middleware('throttle:30,1');

    Route::post('/frete', [FreteController::class, 'calcular'])
        ->middleware('throttle:30,1');

    // ─────────────────────────────────────────────────────────────────────
    // Webhook Mercado Pago (Autenticado via HMAC-SHA256 no header x-signature)
    // ─────────────────────────────────────────────────────────────────────
    Route::post('/webhooks/mercadopago', [MercadoPagoWebhookController::class, 'handle']);
});

// Alias na raiz /api/webhooks/mercadopago caso o Mercado Pago configure sem prefixo v1
Route::post('/webhooks/mercadopago', [MercadoPagoWebhookController::class, 'handle']);