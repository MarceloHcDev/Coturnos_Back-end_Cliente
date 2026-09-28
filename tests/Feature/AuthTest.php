<?php

namespace Tests\Feature;

use App\Models\User;
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
            'cpf' => '123.456.789-00',
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
        $this->assertNotEquals('123.456.789-00', $rawUser->cpf, 'O CPF deve estar criptografado no banco de dados.');
        $this->assertNotEquals('(11) 98765-4321', $rawUser->phone, 'O telefone deve estar criptografado no banco de dados.');

        // Validação de descriptografia transparente pelo Eloquent
        $eloquentUser = User::where('email', 'luis@coturnos.com')->first();
        $this->assertEquals('123.456.789-00', $eloquentUser->cpf);
        $this->assertEquals('(11) 98765-4321', $eloquentUser->phone);
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

        // Teste /logout
        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);

        $this->assertCount(0, $user->tokens);
    }
}
