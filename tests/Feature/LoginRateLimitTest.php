<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Testes do rate limiter de login (Semana 4 — Tarefa 4.3)
 *
 * Garante que:
 *  - 5 tentativas passam normalmente (credenciais erradas → 401/422, não 429).
 *  - A 6ª tentativa retorna 429 com header Retry-After e corpo JSON em PT-BR.
 *  - A chave composta IP + e-mail é independente por e-mail
 *    (dois e-mails distintos no mesmo IP têm contadores separados).
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Limpa o rate limiter antes de cada teste para garantir isolamento.
        RateLimiter::clear($this->rateLimiterKey('test@example.com'));
        RateLimiter::clear($this->rateLimiterKey('other@example.com'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Monta a mesma chave composta que AppServiceProvider::configureRateLimiting() usa.
     */
    private function rateLimiterKey(string $email): string
    {
        return 'login|'.strtolower($email).'|127.0.0.1';
    }

    /**
     * Dispara uma tentativa de login com as credenciais dadas.
     */
    private function attemptLogin(string $email = 'test@example.com', string $password = 'wrongpassword'): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    // -------------------------------------------------------------------------
    // Testes
    // -------------------------------------------------------------------------

    #[Test]
    public function primeiras_cinco_tentativas_nao_retornam_429(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->attemptLogin();
            // Deve ser 401 ou 422 (credenciais inválidas), mas nunca 429.
            $this->assertNotEquals(
                429,
                $response->status(),
                "Tentativa {$i}/5 não deveria ser bloqueada pelo rate limiter."
            );
        }
    }

    #[Test]
    public function sexta_tentativa_retorna_429_com_retry_after(): void
    {
        // Consome as 5 permitidas.
        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin();
        }

        // A 6ª deve ser bloqueada.
        $response = $this->attemptLogin();

        $response->assertStatus(429);
        $response->assertJsonFragment([
            'message' => 'Muitas tentativas. Tente novamente em breve.',
        ]);
        $this->assertTrue(
            $response->headers->has('Retry-After'),
            'A resposta 429 deve conter o header Retry-After.'
        );
    }

    #[Test]
    public function resposta_429_e_json_nao_html(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->attemptLogin();
        }

        $response = $this->attemptLogin();

        $response->assertStatus(429);
        $response->assertHeader('Content-Type', 'application/json');
    }

    #[Test]
    public function chave_e_por_email_diferente_email_tem_contador_proprio(): void
    {
        // Esgota o limite para test@example.com.
        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin('test@example.com');
        }

        // Verifica que test@example.com está bloqueado.
        $this->attemptLogin('test@example.com')->assertStatus(429);

        // Mas other@example.com (mesmo IP) ainda tem seu próprio contador limpo.
        $response = $this->attemptLogin('other@example.com');
        $this->assertNotEquals(
            429,
            $response->status(),
            'E-mails diferentes no mesmo IP devem ter contadores independentes.'
        );
    }
}
