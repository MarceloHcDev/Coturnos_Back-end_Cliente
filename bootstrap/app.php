<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'api/*', // Garante que requisições API não fiquem presas em CSRF
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Retorna 429 em JSON (PT-BR) com header Retry-After preservado.
        // Evita que o Laravel devolva HTML quando a rota é da API.
        $exceptions->render(function (ThrottleRequestsException $e) {
            return response()->json(
                ['message' => 'Muitas tentativas. Tente novamente em breve.'],
                429,
                ['Retry-After' => $e->getHeaders()['Retry-After'] ?? 60]
            );
        });
    })->create();
