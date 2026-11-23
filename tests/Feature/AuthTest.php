<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_sanctum_token(): void
    {
        $payload = [
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '669.677.411-44',
            'email' => 'luis@coturnos.com',
            'phone' => '(11) 98765-4321',
            'birth_date' => '1995-05-15',
            'password' => 'SenhaForte123!',
            'password_confirmation' => 'SenhaForte123!',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => [
                    'id',
                    'first_name',
                    'last_name',
                    'email',
                    'role',
                    'active',
                ],
                'token',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'luis@coturnos.com',
            'role' => 'customer',
            'active' => 1,
        ]);

        // Validação da criptografia LGPD no banco de dados bruto
        $rawUser = DB::table('users')->where('email', 'luis@coturnos.com')->first();
        $this->assertNotEquals('669.677.411-44', $rawUser->cpf, 'O CPF deve estar criptografado no banco de dados.');
        $this->assertNotEquals('(11) 98765-4321', $rawUser->phone, 'O telefone deve estar criptografado no banco de dados.');

        // Validação de descriptografia transparente pelo Eloquent
        $eloquentUser = User::where('email', 'luis@coturnos.com')->first();
        $this->assertEquals('669.677.411-44', $eloquentUser->cpf);
        $this->assertEquals('(11) 98765-4321', $eloquentUser->phone);
    }

    public function test_mass_assignment_cannot_elevate_role_to_admin(): void
    {
        $payload = [
            'first_name' => 'Invasor',
            'last_name' => 'Teste',
            'cpf' => '678.224.375-00',
            'email' => 'hacker@coturnos.com',
            'phone' => '(11) 99999-9999',
            'password' => 'SenhaForte123!',
            'password_confirmation' => 'SenhaForte123!',
            'role' => 'admin', // Tentativa maliciosa de escalada de privilégios
            'active' => false,
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201);

        $user = User::where('email', 'hacker@coturnos.com')->first();

        // Prova que o role permaneceu como customer (protegido contra mass assignment)
        $this->assertEquals(UserRole::CUSTOMER, $user->role);
        $this->assertTrue($user->active);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::create([
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '123.456.789-00',
            'email' => 'luis@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
            'role' => 'customer',
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'luis@coturnos.com',
            'password' => 'SenhaForte123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'user',
                'token',
            ]);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'naoexiste@coturnos.com',
            'password' => 'senhaerrada',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_me_and_logout(): void
    {
        $user = User::create([
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '123.456.789-00',
            'email' => 'luis@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
            'role' => 'customer',
            'active' => true,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        // Teste /me
        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200)
            ->assertJsonPath('user.email', 'luis@coturnos.com');

        // Teste /logout (revoga apenas o token atual)
        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);

        $this->assertCount(0, $user->tokens()->get());
    }

    public function test_logout_all_revokes_all_active_tokens(): void
    {
        $user = User::create([
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '669.677.411-44',
            'email' => 'luis2@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
            'active' => true,
        ]);

        // Simula 3 sessões ativas (celular, PC, tablet)
        $token1 = $user->createToken('mobile')->plainTextToken;
        $token2 = $user->createToken('pc')->plainTextToken;
        $token3 = $user->createToken('tablet')->plainTextToken;

        $this->assertCount(3, $user->tokens()->get());

        // Revoga tudo com um único endpoint
        $response = $this->withHeader('Authorization', "Bearer {$token1}")
            ->postJson('/api/v1/auth/logout-all');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Todas as sessões foram encerradas com sucesso.');

        // Garante que NENHUM token sobrou no banco (prova que todas as sessões foram encerradas)
        // Nota: verificar 401 no mesmo processo de teste não é confiável pois o guard Sanctum
        // reutiliza o estado de autenticação do request anterior em testes. O count 0 no banco é
        // a prova definitiva de que todos os tokens foram revogados.
        $this->assertCount(0, $user->tokens()->get());
    }

    public function test_user_can_update_profile_data_without_blocking(): void
    {
        $user = User::create([
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '123.456.789-00',
            'email' => 'luis@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
        ]);

        // Atualização legítima de dados cadastrais
        $user->update([
            'first_name' => 'Luis Eduardo',
            'phone' => '(11) 91111-2222',
        ]);

        $this->assertEquals('Luis Eduardo', $user->fresh()->first_name);
        $this->assertEquals('(11) 91111-2222', $user->fresh()->phone);
    }

    public function test_user_cannot_update_cpf_after_registration(): void
    {
        $user = User::create([
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '123.456.789-00',
            'email' => 'luis@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O CPF não pode ser alterado após o cadastro.');

        // Tentativa inválida de alterar CPF
        $user->update([
            'cpf' => '999.888.777-66',
        ]);
    }

    public function test_cannot_register_duplicate_cpf_even_with_different_formatting(): void
    {
        // 1. Primeiro cadastro com CPF formatado (com pontos e traço)
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '084.746.062-25',
            'email' => 'luis1@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
            'password_confirmation' => 'SenhaForte123!',
        ])->assertStatus(201);

        // 2. Tentativa de cadastro com o MESMO CPF apenas com dígitos (sem máscara) e outro e-mail
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Outro',
            'last_name' => 'Usuario',
            'cpf' => '08474606225', // Mesmo CPF sem máscara
            'email' => 'outro@coturnos.com',
            'phone' => '(11) 91111-2222',
            'password' => 'SenhaForte123!',
            'password_confirmation' => 'SenhaForte123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cpf_hash'])
            ->assertJsonFragment(['Este CPF já está cadastrado.']);
    }

    public function test_user_can_be_found_by_cpf_using_blind_index(): void
    {
        User::create([
            'first_name' => 'Carlos',
            'last_name' => 'Silva',
            'cpf' => '555.444.333-22',
            'email' => 'carlos@coturnos.com',
            'phone' => '(11) 97777-8888',
            'password' => 'SenhaForte123!',
        ]);

        // Busca com máscara
        $foundFormatted = User::findByCpf('555.444.333-22');
        $this->assertNotNull($foundFormatted);
        $this->assertEquals('carlos@coturnos.com', $foundFormatted->email);

        // Busca sem máscara
        $foundRaw = User::findByCpf('55544433322');
        $this->assertNotNull($foundRaw);
        $this->assertEquals('carlos@coturnos.com', $foundRaw->email);
    }

    public function test_user_role_enum_casting_and_methods(): void
    {
        $customer = User::create([
            'first_name' => 'Cliente',
            'last_name' => 'Teste',
            'cpf' => '111.222.333-44',
            'email' => 'cliente@coturnos.com',
            'phone' => '(11) 91234-5678',
            'password' => 'SenhaForte123!',
        ]);

        $this->assertInstanceOf(UserRole::class, $customer->role);
        $this->assertEquals(UserRole::CUSTOMER, $customer->role);
        $this->assertTrue($customer->isCustomer());
        $this->assertFalse($customer->isAdmin());

        // Criar admin com forceFill
        $admin = new User([
            'first_name' => 'Admin',
            'last_name' => 'Sistema',
            'cpf' => '999.888.777-00',
            'email' => 'admin@coturnos.com',
            'phone' => '(11) 98888-7777',
            'password' => 'SenhaForte123!',
        ]);
        $admin->role = UserRole::ADMIN;
        $admin->save();

        $this->assertInstanceOf(UserRole::class, $admin->role);
        $this->assertEquals(UserRole::ADMIN, $admin->role);
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->isCustomer());
    }

    public function test_address_creation_via_relationship_anti_idor(): void
    {
        $user = User::create([
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '123.456.789-00',
            'email' => 'luis@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
        ]);

        // Criação segura via relacionamento (user_id injetado automaticamente pelo Eloquent)
        $address = $user->addresses()->create([
            'zip_code' => '01001-000',
            'street' => 'Praça da Sé',
            'number' => '100',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
            'is_default' => true,
        ]);

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'user_id' => $user->id,
            'zip_code' => '01001-000',
        ]);

        $this->assertEquals($user->id, $address->user_id);
    }

    public function test_only_one_address_can_be_default_per_user(): void
    {
        $user = User::create([
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '123.456.789-00',
            'email' => 'luis@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
        ]);

        // 1. Cria primeiro endereço como padrão
        $address1 = $user->addresses()->create([
            'zip_code' => '01001-000',
            'street' => 'Primeira Rua',
            'number' => '1',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'is_default' => true,
        ]);

        $this->assertTrue($address1->fresh()->is_default);

        // 2. Cria segundo endereço também como padrão
        $address2 = $user->addresses()->create([
            'zip_code' => '02002-000',
            'street' => 'Segunda Rua',
            'number' => '2',
            'neighborhood' => 'Bairro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'is_default' => true,
        ]);

        // O primeiro endereço DEVE ter sido desmarcado automaticamente
        $this->assertFalse($address1->fresh()->is_default);
        // O segundo endereço DEVE ser o novo padrão ativo
        $this->assertTrue($address2->fresh()->is_default);
    }

    public function test_address_soft_deletes_preserves_historical_records(): void
    {
        $user = User::create([
            'first_name' => 'Luis',
            'last_name' => 'Eduardo',
            'cpf' => '123.456.789-00',
            'email' => 'luis@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
        ]);

        $address = $user->addresses()->create([
            'zip_code' => '01001-000',
            'street' => 'Rua Histórica',
            'number' => '42',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
            'is_default' => true,
        ]);

        // Cliente remove o endereço pelo painel
        $address->delete();

        // 1. O endereço não aparece mais na listagem ativa do cliente
        $this->assertEquals(0, $user->addresses()->count());

        // 2. O registro FÍSICO continua no banco de dados com deleted_at preenchido (preserva histórico de pedidos e NF-e)
        $this->assertSoftDeleted('addresses', [
            'id' => $address->id,
            'zip_code' => '01001-000',
        ]);

        // 3. Pode ser resgatado para consulta de pedidos antigos
        $this->assertEquals(1, $user->addresses()->withTrashed()->count());
    }

    public function test_social_accounts_enforces_unique_provider_id_and_provider_name_constraint(): void
    {
        $user1 = User::create([
            'first_name' => 'Usuario',
            'last_name' => 'Um',
            'cpf' => '111.111.111-11',
            'email' => 'user1@coturnos.com',
            'phone' => '(11) 91111-1111',
            'password' => 'SenhaForte123!',
        ]);

        $user2 = User::create([
            'first_name' => 'Usuario',
            'last_name' => 'Dois',
            'cpf' => '222.222.222-22',
            'email' => 'user2@coturnos.com',
            'phone' => '(11) 92222-2222',
            'password' => 'SenhaForte123!',
        ]);

        // 1. Vincula conta Google com ID 123456 ao Usuario 1
        $user1->socialAccounts()->create([
            'provider_name' => 'google',
            'provider_id' => 'google-uid-123456',
        ]);

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user1->id,
            'provider_name' => 'google',
            'provider_id' => 'google-uid-123456',
        ]);

        // 2. Tentar vincular o MESMO provider_id do Google ao Usuario 2 DEVE falhar por constraint única
        $this->expectException(QueryException::class);

        $user2->socialAccounts()->create([
            'provider_name' => 'google',
            'provider_id' => 'google-uid-123456',
        ]);
    }

    public function test_social_account_find_by_provider_helper(): void
    {
        $user = User::create([
            'first_name' => 'Ana',
            'last_name' => 'Lima',
            'cpf' => '333.444.555-66',
            'email' => 'ana@coturnos.com',
            'phone' => '(11) 93333-3333',
            'password' => 'SenhaForte123!',
        ]);

        $user->socialAccounts()->create([
            'provider_name' => 'google',
            'provider_id' => 'google-uid-999',
        ]);

        // Busca existente
        $found = SocialAccount::findByProvider('google', 'google-uid-999');
        $this->assertNotNull($found);
        $this->assertEquals($user->id, $found->user_id);
        $this->assertEquals('ana@coturnos.com', $found->user->email);

        // Busca inexistente
        $notFound = SocialAccount::findByProvider('google', 'nao-existe');
        $this->assertNull($notFound);
    }

    public function test_registration_fails_with_invalid_cpf(): void
    {
        // 1. CPF com dígitos repetidos
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Teste',
            'last_name' => 'Invalido',
            'cpf' => '111.111.111-11',
            'email' => 'cpf1@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
            'password_confirmation' => 'SenhaForte123!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['cpf']);

        // 2. CPF com dígitos verificadores matematicamente errados
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Teste',
            'last_name' => 'Invalido',
            'cpf' => '123.456.789-00',
            'email' => 'cpf2@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
            'password_confirmation' => 'SenhaForte123!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['cpf']);
    }

    public function test_registration_normalizes_email_to_lowercase(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Pedro',
            'last_name' => 'Santos',
            'cpf' => '344.306.951-72',
            'email' => 'PEDRO.SANTOS@COTURNOS.COM',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
            'password_confirmation' => 'SenhaForte123!',
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'pedro.santos@coturnos.com',
        ]);
    }

    public function test_registration_fails_with_invalid_phone_format(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Teste',
            'last_name' => 'Telefone',
            'cpf' => '448.979.328-66',
            'email' => 'tel@coturnos.com',
            'phone' => '12345', // Inválido
            'password' => 'SenhaForte123!',
            'password_confirmation' => 'SenhaForte123!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_registration_fails_with_future_birth_date(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Teste',
            'last_name' => 'Nascimento',
            'cpf' => '448.979.328-66',
            'email' => 'futuro@coturnos.com',
            'phone' => '(11) 98765-4321',
            'birth_date' => now()->addYear()->format('Y-m-d'),
            'password' => 'SenhaForte123!',
            'password_confirmation' => 'SenhaForte123!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['birth_date']);
    }

    public function test_registration_fails_with_weak_or_oversized_password(): void
    {
        // 1. Senha sem símbolo ou maiúscula
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Teste',
            'last_name' => 'Senha',
            'cpf' => '448.979.328-66',
            'email' => 'senha1@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'apenasletrasminusculas',
            'password_confirmation' => 'apenasletrasminusculas',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // 2. Senha gigante (vetor DoS > 128 chars)
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Teste',
            'last_name' => 'Senha',
            'cpf' => '448.979.328-66',
            'email' => 'senha2@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => str_repeat('Aa!', 45), // 135 chars
            'password_confirmation' => str_repeat('Aa!', 45),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_user_can_register_with_frontend_payload_shape_and_address(): void
    {
        $payload = [
            'name' => 'Fabio Rabello Silva',
            'cpf' => '08474606225',
            'email' => 'fabio.front@coturnos.com',
            'phone' => '11987654321',
            'birthdate' => '15/05/1995', // Formato DD/MM/YYYY do front
            'password' => 'Senha@Forte', // MixedCase + Symbol, sem número e sem confirmação
            'zip' => '01001000',
            'street' => 'Praça da Sé',
            'number' => '100',
            'complement' => 'Apto 42',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('user.first_name', 'Fabio')
            ->assertJsonPath('user.last_name', 'Rabello Silva')
            ->assertJsonPath('user.email', 'fabio.front@coturnos.com');

        $user = User::where('email', 'fabio.front@coturnos.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('1995-05-15', $user->birth_date->format('Y-m-d'));

        // Verifica criação do endereço padrão vinculado ao usuário
        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'zip_code' => '01001000',
            'street' => 'Praça da Sé',
            'number' => '100',
            'is_default' => 1,
        ]);
    }

    public function test_registration_fails_with_age_less_than_16(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Menor de Idade',
            'cpf' => '344.306.951-72',
            'email' => 'menor@coturnos.com',
            'phone' => '(11) 98765-4321',
            'birthdate' => now()->subYears(15)->format('d/m/Y'),
            'password' => 'Senha@Forte',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['birth_date'])
            ->assertJsonFragment(['É necessário ter pelo menos 16 anos para se cadastrar.']);
    }

    public function test_registration_fails_with_age_more_than_110(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Muito Idoso',
            'cpf' => '344.306.951-72',
            'email' => 'idoso@coturnos.com',
            'phone' => '(11) 98765-4321',
            'birthdate' => now()->subYears(115)->format('d/m/Y'),
            'password' => 'Senha@Forte',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['birth_date'])
            ->assertJsonFragment(['A data de nascimento informada é inválida.']);
    }

    public function test_user_can_login_with_uppercase_email_due_to_normalization(): void
    {
        User::create([
            'first_name' => 'Carlos',
            'last_name' => 'Eduardo',
            'cpf' => '669.677.411-44',
            'email' => 'carlos.edu@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'CARLOS.EDU@COTURNOS.COM',
            'password' => 'SenhaForte123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.email', 'carlos.edu@coturnos.com');
    }

    public function test_login_fails_with_oversized_payload_anti_dos(): void
    {
        // 1. Email maior que 191 caracteres
        $this->postJson('/api/v1/auth/login', [
            'email' => str_repeat('a', 185).'@coturnos.com', // > 191 chars
            'password' => 'SenhaForte123!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        // 2. Senha gigante (> 128 chars)
        $this->postJson('/api/v1/auth/login', [
            'email' => 'carlos@coturnos.com',
            'password' => str_repeat('x', 130),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
