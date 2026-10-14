<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function createCustomer(array $overrides = []): User
    {
        return User::create(array_merge([
            'first_name' => 'Carlos',
            'last_name' => 'Silva',
            'cpf' => '123.456.789-00',
            'email' => 'carlos@coturnos.com',
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaAtual123!',
            'role' => 'customer',
            'active' => true,
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Testes de PUT /api/v1/perfil
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function usuario_autenticado_pode_atualizar_nome_e_telefone(): void
    {
        $user = $this->createCustomer();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/perfil', [
                'first_name' => 'Carlos Eduardo',
                'last_name' => 'Oliveira',
                'phone' => '11999998888',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Perfil atualizado com sucesso.')
            ->assertJsonPath('user.first_name', 'Carlos Eduardo')
            ->assertJsonPath('user.last_name', 'Oliveira')
            ->assertJsonPath('user.cpf', '***.456.789-**');

        $user->refresh();
        $this->assertEquals('Carlos Eduardo', $user->first_name);
        $this->assertEquals('Oliveira', $user->last_name);
        $this->assertEquals('11999998888', $user->phone);
    }

    #[Test]
    public function prevencao_mass_assignment_ignora_campos_sensiveis(): void
    {
        $user = $this->createCustomer();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/perfil', [
                'first_name' => 'NovoNome',
                'role' => 'admin',
                'active' => false,
                'email' => 'hacker@malicioso.com',
                'cpf' => '999.888.777-66',
            ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('NovoNome', $user->first_name);
        $this->assertEquals('customer', $user->role->value);
        $this->assertTrue((bool) $user->active);
        $this->assertEquals('carlos@coturnos.com', $user->email);
        $this->assertEquals('123.456.789-00', $user->cpf);
    }

    #[Test]
    public function rota_de_perfil_exige_autenticacao(): void
    {
        $response = $this->putJson('/api/v1/perfil', [
            'first_name' => 'Teste',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function rejeita_telefone_com_formato_invalido(): void
    {
        $user = $this->createCustomer();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/perfil', [
                'phone' => '12345',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Testes de PUT /api/v1/perfil/senha
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function troca_de_senha_com_sucesso_e_revoga_outros_tokens(): void
    {
        $user = $this->createCustomer();

        $token1 = $user->createToken('sessao-antiga-1')->plainTextToken;
        $token2 = $user->createToken('sessao-antiga-2')->plainTextToken;
        $tokenAtual = $user->createToken('sessao-atual');

        $this->assertCount(3, $user->tokens);

        $response = $this->withHeader('Authorization', "Bearer {$tokenAtual->plainTextToken}")
            ->putJson('/api/v1/perfil/senha', [
                'current_password' => 'SenhaAtual123!',
                'password' => 'NovaSenha456!',
                'password_confirmation' => 'NovaSenha456!',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Senha alterada com sucesso. Outras sessões foram encerradas.');

        // Garante que a nova senha funciona para login
        $user->refresh();
        $this->assertTrue(Hash::check('NovaSenha456!', $user->password));

        // Apenas o tokenAtual deve permanecer ativo no banco de dados
        $this->assertCount(1, $user->tokens()->get());
        $this->assertEquals($tokenAtual->accessToken->id, $user->tokens()->first()->id);
    }

    #[Test]
    public function falha_ao_trocar_senha_com_senha_atual_errada(): void
    {
        $user = $this->createCustomer();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/perfil/senha', [
                'current_password' => 'SenhaErrada123!',
                'password' => 'NovaSenha456!',
                'password_confirmation' => 'NovaSenha456!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    #[Test]
    public function falha_ao_trocar_senha_quando_nova_senha_e_igual_a_atual(): void
    {
        $user = $this->createCustomer();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/perfil/senha', [
                'current_password' => 'SenhaAtual123!',
                'password' => 'SenhaAtual123!',
                'password_confirmation' => 'SenhaAtual123!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    #[Test]
    public function falha_ao_trocar_senha_com_confirmacao_divergente_ou_fraca(): void
    {
        $user = $this->createCustomer();
        $token = $user->createToken('test')->plainTextToken;

        // Confirmação diferente
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/perfil/senha', [
                'current_password' => 'SenhaAtual123!',
                'password' => 'NovaSenha456!',
                'password_confirmation' => 'Divergente456!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // Senha fraca (sem número, sem maiúscula, menos de 8 caracteres)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/perfil/senha', [
                'current_password' => 'SenhaAtual123!',
                'password' => 'fraca',
                'password_confirmation' => 'fraca',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    #[Test]
    public function usuario_social_sem_senha_pode_definir_primeira_senha_sem_senha_atual(): void
    {
        $user = $this->createCustomer(['password' => null]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/perfil/senha', [
                'password' => 'PrimeiraSenhaForte123!',
                'password_confirmation' => 'PrimeiraSenhaForte123!',
            ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertTrue(Hash::check('PrimeiraSenhaForte123!', $user->password));
    }
}
