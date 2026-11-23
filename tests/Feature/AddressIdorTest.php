<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AddressIdorTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $email, string $cpf): User
    {
        return User::create([
            'first_name' => 'Usuario',
            'last_name' => 'Teste',
            'cpf' => $cpf,
            'email' => $email,
            'phone' => '(11) 98765-4321',
            'password' => 'SenhaForte123!',
            'role' => 'customer',
            'active' => true,
        ]);
    }

    private function createAddressFor(User $user, array $overrides = []): Address
    {
        return $user->addresses()->create(array_merge([
            'zip_code' => '01001000',
            'street' => 'Praça da Sé',
            'number' => '100',
            'complement' => 'Apto 1',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
            'is_default' => false,
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Testes de Anti-IDOR
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function usuario_a_nao_pode_visualizar_endereco_de_usuario_b(): void
    {
        $userA = $this->createUser('usera@coturnos.com', '111.111.111-11');
        $userB = $this->createUser('userb@coturnos.com', '222.222.222-22');

        $addressB = $this->createAddressFor($userB);

        $tokenA = $userA->createToken('test')->plainTextToken;

        // Tenta acessar o endereço de B com o token de A -> deve retornar 404 (não 403)
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson("/api/v1/enderecos/{$addressB->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function usuario_a_nao_pode_editar_endereco_de_usuario_b(): void
    {
        $userA = $this->createUser('usera@coturnos.com', '111.111.111-11');
        $userB = $this->createUser('userb@coturnos.com', '222.222.222-22');

        $addressB = $this->createAddressFor($userB, ['street' => 'Rua Original']);

        $tokenA = $userA->createToken('test')->plainTextToken;

        // Tenta alterar o endereço de B com o token de A
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->putJson("/api/v1/enderecos/{$addressB->id}", [
                'street' => 'Rua Hacker Modificada',
            ]);

        $response->assertStatus(404);

        $addressB->refresh();
        $this->assertEquals('Rua Original', $addressB->street);
    }

    #[Test]
    public function usuario_a_nao_pode_excluir_endereco_de_usuario_b(): void
    {
        $userA = $this->createUser('usera@coturnos.com', '111.111.111-11');
        $userB = $this->createUser('userb@coturnos.com', '222.222.222-22');

        $addressB = $this->createAddressFor($userB);

        $tokenA = $userA->createToken('test')->plainTextToken;

        // Tenta deletar o endereço de B com o token de A
        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->deleteJson("/api/v1/enderecos/{$addressB->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('addresses', [
            'id' => $addressB->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function usuario_a_nao_pode_definir_endereco_de_usuario_b_como_padrao(): void
    {
        $userA = $this->createUser('usera@coturnos.com', '111.111.111-11');
        $userB = $this->createUser('userb@coturnos.com', '222.222.222-22');

        $addressB = $this->createAddressFor($userB, ['is_default' => false]);

        $tokenA = $userA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->patchJson("/api/v1/enderecos/{$addressB->id}/padrao");

        $response->assertStatus(404);

        $addressB->refresh();
        $this->assertFalse((bool) $addressB->is_default);
    }

    #[Test]
    public function tentativa_de_injetar_user_id_alheio_no_cadastro_e_ignorada(): void
    {
        $userA = $this->createUser('usera@coturnos.com', '111.111.111-11');
        $userB = $this->createUser('userb@coturnos.com', '222.222.222-22');

        $tokenA = $userA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/enderecos', [
                'user_id' => $userB->id,
                'usuario_id' => $userB->id,
                'cep' => '01001-000',
                'logradouro' => 'Rua Segura',
                'numero' => '123',
                'bairro' => 'Centro',
                'cidade' => 'São Paulo',
                'estado' => 'SP',
            ]);

        $response->assertStatus(201);

        $createdId = $response->json('data.id');
        $createdAddress = Address::find($createdId);

        // O endereço DEVE pertencer ao userA, NUNCA ao userB
        $this->assertEquals($userA->id, $createdAddress->user_id);
        $this->assertNotEquals($userB->id, $createdAddress->user_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Testes de CRUD Funcional e Regras de Negócio
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function lista_somente_enderecos_do_usuario_autenticado(): void
    {
        $userA = $this->createUser('usera@coturnos.com', '111.111.111-11');
        $userB = $this->createUser('userb@coturnos.com', '222.222.222-22');

        $this->createAddressFor($userA, ['street' => 'Rua do User A 1']);
        $this->createAddressFor($userA, ['street' => 'Rua do User A 2']);
        $this->createAddressFor($userB, ['street' => 'Rua do User B']);

        $tokenA = $userA->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/api/v1/enderecos');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $streets = collect($response->json('data'))->pluck('street')->all();
        $this->assertContains('Rua do User A 1', $streets);
        $this->assertContains('Rua do User A 2', $streets);
        $this->assertNotContains('Rua do User B', $streets);
    }

    #[Test]
    public function primeiro_endereco_criado_torna_se_padrao_automaticamente(): void
    {
        $user = $this->createUser('user@coturnos.com', '123.456.789-00');
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/enderecos', [
                'zip_code' => '01001000',
                'street' => 'Rua Primeira',
                'number' => '1',
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
            ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.is_default'));
    }

    #[Test]
    public function definir_novo_padrao_desmarca_automaticamente_o_anterior(): void
    {
        $user = $this->createUser('user@coturnos.com', '123.456.789-00');
        $address1 = $this->createAddressFor($user, ['street' => 'Endereço 1', 'is_default' => true]);
        $address2 = $this->createAddressFor($user, ['street' => 'Endereço 2', 'is_default' => false]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/enderecos/{$address2->id}/padrao");

        $response->assertStatus(200);

        $address1->refresh();
        $address2->refresh();

        $this->assertFalse((bool) $address1->is_default);
        $this->assertTrue((bool) $address2->is_default);
    }

    #[Test]
    public function exclusao_de_endereco_utiliza_soft_delete(): void
    {
        $user = $this->createUser('user@coturnos.com', '123.456.789-00');
        $address = $this->createAddressFor($user);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/enderecos/{$address->id}");

        $response->assertStatus(200);

        // Registro ainda existe no banco de dados com deleted_at preenchido
        $this->assertDatabaseHas('addresses', ['id' => $address->id]);
        $this->assertNotNull(Address::withTrashed()->find($address->id)->deleted_at);
        $this->assertNull(Address::find($address->id));
    }

    #[Test]
    public function rotas_de_enderecos_exigem_autenticacao(): void
    {
        $this->getJson('/api/v1/enderecos')->assertStatus(401);
        $this->postJson('/api/v1/enderecos', [])->assertStatus(401);
        $this->getJson('/api/v1/enderecos/1')->assertStatus(401);
        $this->putJson('/api/v1/enderecos/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/enderecos/1')->assertStatus(401);
        $this->patchJson('/api/v1/enderecos/1/padrao')->assertStatus(401);
    }
}
