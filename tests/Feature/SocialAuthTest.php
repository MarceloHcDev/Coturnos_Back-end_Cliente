<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Garante whitelist vazia por padrão nos testes (comportamento de produção = libera tudo).
        // Os testes de whitelist sobrescrevem isso individualmente via config([...]).
        config(['services.social_allowed_emails' => '']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper para mockar o retorno do Laravel Socialite com segurança.
     */
    protected function mockSocialite(
        string $provider,
        string $id,
        string $email,
        string $name,
        bool $verified = true
    ): void {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($id);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getRaw')->andReturn([
            'email_verified' => $verified,
            'verified_email' => $verified,
        ]);

        $providerMock = Mockery::mock(AbstractProvider::class);
        $providerMock->shouldReceive('stateless')->andReturnSelf();
        $providerMock->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with($provider)->andReturn($providerMock);
    }

    public function test_rejects_unsupported_provider_not_in_whitelist(): void
    {
        $response = $this->getJson('/api/v1/auth/github/callback');

        $response->assertStatus(400)
            ->assertJsonPath('message', "Provedor social 'github' não suportado.");
    }

    public function test_first_social_login_creates_new_user_and_links_account(): void
    {
        $this->mockSocialite('google', 'google-uid-001', 'novo@coturnos.com', 'Lucas Silva', true);

        $response = $this->getJson('/api/v1/auth/google/callback');

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'first_name', 'last_name', 'email', 'role', 'active'],
                'token',
            ])
            ->assertJsonPath('user.first_name', 'Lucas')
            ->assertJsonPath('user.last_name', 'Silva')
            ->assertJsonPath('user.email', 'novo@coturnos.com');

        // Valida que o usuário foi criado no banco
        $user = User::where('email', 'novo@coturnos.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->password, 'A senha deve ser nula para cadastro exclusivamente social.');
        $this->assertEquals(UserRole::CUSTOMER, $user->role);

        // Valida que a SocialAccount foi vinculada
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider_name' => 'google',
            'provider_id' => 'google-uid-001',
        ]);
    }

    public function test_second_social_login_with_same_provider_id_authenticates_without_duplication(): void
    {
        // 1. Cria usuário e vínculo prévio
        $user = User::create([
            'first_name' => 'Mariana',
            'last_name' => 'Costa',
            'email' => 'mariana@coturnos.com',
            'password' => null,
        ]);

        $user->socialAccounts()->create([
            'provider_name' => 'google',
            'provider_id' => 'google-uid-002',
        ]);

        $this->assertCount(1, User::where('email', 'mariana@coturnos.com')->get());
        $this->assertCount(1, SocialAccount::where('provider_id', 'google-uid-002')->get());

        // 2. Simula segundo login com mesmo provider_id
        $this->mockSocialite('google', 'google-uid-002', 'mariana@coturnos.com', 'Mariana Costa', true);

        $response = $this->getJson('/api/v1/auth/google/callback');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Login social realizado com sucesso.')
            ->assertJsonPath('user.email', 'mariana@coturnos.com');

        // Garante que não houve duplicação de usuário nem de SocialAccount
        $this->assertEquals(1, User::where('email', 'mariana@coturnos.com')->count());
        $this->assertEquals(1, SocialAccount::where('provider_id', 'google-uid-002')->count());
    }

    public function test_cannot_link_same_provider_id_to_another_user_due_to_unique_constraint(): void
    {
        $user1 = User::create([
            'first_name' => 'Usuario',
            'last_name' => 'Um',
            'email' => 'user1@coturnos.com',
        ]);

        $user2 = User::create([
            'first_name' => 'Usuario',
            'last_name' => 'Dois',
            'email' => 'user2@coturnos.com',
        ]);

        // Vincula Google ID ao usuário 1
        $user1->socialAccounts()->create([
            'provider_name' => 'google',
            'provider_id' => 'google-unique-test',
        ]);

        // Tentar vincular o mesmo provider_id ao usuário 2 DEVE falhar pela constraint única
        $this->expectException(QueryException::class);

        $user2->socialAccounts()->create([
            'provider_name' => 'google',
            'provider_id' => 'google-unique-test',
        ]);
    }

    public function test_social_login_links_existing_user_when_email_is_verified_by_provider(): void
    {
        // 1. Usuário previamente cadastrado via formulário tradicional
        $user = User::create([
            'first_name' => 'Rafael',
            'last_name' => 'Ferreira',
            'email' => 'rafael@coturnos.com',
            'password' => Hash::make('SenhaSegura123!'),
        ]);

        $this->assertCount(0, $user->socialAccounts);

        // 2. Login social com o mesmo e-mail E com email_verified = true
        $this->mockSocialite('facebook', 'fb-uid-777', 'rafael@coturnos.com', 'Rafael Ferreira', true);

        $response = $this->getJson('/api/v1/auth/facebook/callback');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Conta vinculada e login realizado com sucesso.')
            ->assertJsonPath('user.email', 'rafael@coturnos.com');

        // Verifica que o vínculo foi criado na conta existente
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider_name' => 'facebook',
            'provider_id' => 'fb-uid-777',
        ]);

        $this->assertEquals(1, User::where('email', 'rafael@coturnos.com')->count());
    }

    public function test_anti_account_takeover_blocks_auto_link_when_email_is_not_verified_by_provider(): void
    {
        // 1. Usuário vítima que já possui conta com senha
        $vitima = User::create([
            'first_name' => 'Vitima',
            'last_name' => 'Segura',
            'email' => 'alvo@coturnos.com',
            'password' => Hash::make('SenhaDaVitima123!'),
        ]);

        // 2. Atacante tenta logar socialmente com o e-mail da vítima, mas com email_verified = false
        $this->mockSocialite('google', 'google-atacante-id', 'alvo@coturnos.com', 'Atacante Hacker', false);

        $response = $this->getJson('/api/v1/auth/google/callback');

        // DEVE bloquear o vínculo automático com status 409 e solicitar senha
        $response->assertStatus(409)
            ->assertJsonPath('requires_password_confirmation', true)
            ->assertJsonPath('email', 'alvo@coturnos.com')
            ->assertJsonPath('provider', 'google')
            ->assertJsonPath('provider_id', 'google-atacante-id');

        // NENHUM vínculo pode ter sido criado no banco
        $this->assertDatabaseMissing('social_accounts', [
            'provider_id' => 'google-atacante-id',
        ]);

        // 3. Confirmar o vínculo fornecendo a senha correta da conta
        $confirmResponse = $this->postJson('/api/v1/auth/google/confirm-link', [
            'email' => 'alvo@coturnos.com',
            'password' => 'SenhaDaVitima123!',
            'provider_id' => 'google-atacante-id',
        ]);

        $confirmResponse->assertStatus(200)
            ->assertJsonPath('message', 'Conta vinculada com sucesso após confirmação.');

        // Agora sim, com senha confirmada, o vínculo existe
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $vitima->id,
            'provider_name' => 'google',
            'provider_id' => 'google-atacante-id',
        ]);
    }

    public function test_token_issued_contains_correct_abilities_based_on_user_role(): void
    {
        // 1. Usuário com papel de cliente
        $customer = User::create([
            'first_name' => 'Cliente',
            'last_name' => 'Normal',
            'email' => 'cliente.role@coturnos.com',
            'password' => null,
        ]);
        $customer->role = UserRole::CUSTOMER;
        $customer->save();

        $customerToken = $customer->createToken('test', $customer->tokenAbilities());
        $this->assertEquals(['customer'], $customerToken->accessToken->abilities);

        // 2. Usuário com papel de administrador
        $admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'Master',
            'email' => 'admin.role@coturnos.com',
            'password' => null,
        ]);
        $admin->role = UserRole::ADMIN;
        $admin->save();

        $adminToken = $admin->createToken('test', $admin->tokenAbilities());
        $this->assertEquals(['admin', 'customer'], $adminToken->accessToken->abilities);
    }

    public function test_social_callback_endpoint_has_rate_limiting(): void
    {
        // O middleware throttle:10,1 permite até 10 requisições por minuto
        for ($i = 0; $i < 10; $i++) {
            $this->mockSocialite('google', "uid-{$i}", "user{$i}@coturnos.com", "User {$i}", true);
            $this->getJson('/api/v1/auth/google/callback');
        }

        // A 11ª requisição deve retornar 429 Too Many Requests
        $this->mockSocialite('google', 'uid-11', 'user11@coturnos.com', 'User 11', true);
        $response = $this->getJson('/api/v1/auth/google/callback');

        $response->assertStatus(429);
    }

    public function test_blocks_unauthorized_email_when_whitelist_is_configured(): void
    {
        config(['services.social_allowed_emails' => 'autorizado@coturnos.com']);

        $this->mockSocialite('google', 'google-invasor', 'desconhecido@gmail.com', 'Invasor Teste', true);
        $response = $this->getJson('/api/v1/auth/google/callback');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Acesso social restrito em ambiente de homologação. E-mail não autorizado para testes.');
    }

    public function test_allows_authorized_email_when_whitelist_is_configured(): void
    {
        config(['services.social_allowed_emails' => 'autorizado@coturnos.com']);

        $this->mockSocialite('google', 'google-ok', 'autorizado@coturnos.com', 'Usuario Autorizado', true);
        $response = $this->getJson('/api/v1/auth/google/callback');

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'autorizado@coturnos.com');
    }
}
