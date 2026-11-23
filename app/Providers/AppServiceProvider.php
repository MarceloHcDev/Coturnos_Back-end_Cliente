<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, [AppleExtendSocialite::class, 'handle']);

        $this->configureRateLimiting();
    }

    /**
     * Define os rate limiters customizados da aplicação.
     *
     * 'login' usa chave composta IP + e-mail (lowercase) para:
     *   - Evitar que um atacante contorne o limite trocando de IP.
     *   - Evitar bloqueio de usuários legítimos por trás do mesmo NAT
     *     (já que o e-mail alvo também integra a chave).
     *   - Máximo: 5 tentativas por minuto; resposta 429 em JSON + Retry-After.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email', ''))
                .'|'
                .$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
