<?php

namespace Tests\Feature\Auth;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    // Datos base válidos para registro
    private function validRegisterData(array $overrides = []): array
    {
        return array_merge([
            'organization_name' => 'Mi Empresa SA',
            'email' => 'admin@test.com',
            'name' => 'Admin Test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'accepted_terms' => true,
        ], $overrides);
    }

    // =========================================================
    // REGISTER
    // =========================================================

    public function test_register_crea_organizacion_y_usuario_admin(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegisterData());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'Admin')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'email', 'name', 'role'],
                    'organization' => ['id', 'name', 'invite_code'],
                    'access_token',
                ],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'admin@test.com', 'role' => 'Admin']);
        $this->assertDatabaseHas('organizations', ['name' => 'Mi Empresa SA']);
    }

    public function test_register_con_invite_code_une_organizacion_como_operador(): void
    {
        $org = $this->createOrganization(['name' => 'Empresa Existente']);

        $response = $this->postJson('/api/v1/auth/register', [
            'invite_code' => $org->invite_code,
            'email' => 'operador@test.com',
            'name' => 'Operador Test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'accepted_terms' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.role', 'Operador');

        $this->assertDatabaseHas('users', [
            'email' => 'operador@test.com',
            'organization_id' => $org->id,
            'role' => 'Operador',
        ]);
    }

    public function test_register_falla_sin_organizacion_ni_invite_code(): void
    {
        $data = $this->validRegisterData();
        unset($data['organization_name']);

        $this->postJson('/api/v1/auth/register', $data)
            ->assertStatus(422);
    }

    public function test_register_falla_con_invite_code_invalido(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validRegisterData([
            'organization_name' => null,
            'invite_code' => 'INVALIDO',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['invite_code']);
    }

    public function test_register_falla_con_email_duplicado(): void
    {
        $org = $this->createOrganization();
        $this->createUser($org, 'Admin', ['email' => 'admin@test.com']);

        $this->postJson('/api/v1/auth/register', $this->validRegisterData())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_falla_con_contrasena_sin_mayuscula_ni_numero(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validRegisterData([
            'password' => 'sinmayuscula',
            'password_confirmation' => 'sinmayuscula',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_falla_sin_aceptar_terminos(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validRegisterData([
            'accepted_terms' => false,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors(['accepted_terms']);
    }

    // =========================================================
    // LOGIN
    // =========================================================

    public function test_login_exitoso_con_credenciales_validas(): void
    {
        $org = $this->createOrganization();
        $this->createUser($org, 'Admin', ['email' => 'admin@test.com', 'password' => 'Password1']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'Password1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['user', 'organization', 'access_token', 'token_type'],
            ]);
    }

    public function test_login_falla_con_contrasena_incorrecta(): void
    {
        $org = $this->createOrganization();
        $this->createUser($org, 'Admin', ['email' => 'admin@test.com', 'password' => 'Password1']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'Incorrecta1',
        ])->assertStatus(401);
    }

    public function test_login_falla_con_usuario_inactivo(): void
    {
        $org = $this->createOrganization();
        $this->createUser($org, 'Operador', [
            'email' => 'inactivo@test.com',
            'password' => 'Password1',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactivo@test.com',
            'password' => 'Password1',
        ])->assertStatus(403);
    }

    public function test_login_falla_con_email_inexistente(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'noexiste@test.com',
            'password' => 'Password1',
        ])->assertStatus(401);
    }

    // =========================================================
    // ME
    // =========================================================

    public function test_me_retorna_usuario_autenticado(): void
    {
        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role', 'Admin');
    }

    public function test_me_falla_sin_autenticacion(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    // =========================================================
    // CHANGE PASSWORD
    // =========================================================

    public function test_cambio_contrasena_exitoso(): void
    {
        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin', ['password' => 'OldPassword1']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'OldPassword1',
                'new_password' => 'NewPassword2',
                'new_password_confirmation' => 'NewPassword2',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_cambio_contrasena_falla_con_contrasena_actual_incorrecta(): void
    {
        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin', ['password' => 'OldPassword1']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'Incorrecta1',
                'new_password' => 'NewPassword2',
                'new_password_confirmation' => 'NewPassword2',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_cambio_contrasena_falla_si_es_igual_a_la_actual(): void
    {
        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin', ['password' => 'Password1']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'Password1',
                'new_password' => 'Password1',
                'new_password_confirmation' => 'Password1',
            ])->assertStatus(422);
    }

    public function test_cambio_contrasena_falla_si_confirmacion_no_coincide(): void
    {
        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin', ['password' => 'Password1']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'Password1',
                'new_password' => 'NewPassword2',
                'new_password_confirmation' => 'OtroValor9',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    // =========================================================
    // FORGOT PASSWORD
    // =========================================================

    public function test_forgot_password_envia_notificacion_si_email_existe(): void
    {
        Notification::fake();

        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin', ['email' => 'user@test.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'user@test.com'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_forgot_password_retorna_exito_aunque_email_no_exista(): void
    {
        // No revelar si el email está registrado
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'noexiste@test.com'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_forgot_password_falla_con_email_invalido(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'no-es-un-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // =========================================================
    // RESET PASSWORD
    // =========================================================

    public function test_reset_password_exitoso_con_token_valido(): void
    {
        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin', ['email' => 'user@test.com', 'password' => 'OldPassword1']);

        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@test.com',
            'token' => $token,
            'password' => 'NewPassword2',
            'password_confirmation' => 'NewPassword2',
        ])->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_reset_password_falla_con_token_invalido(): void
    {
        $org = $this->createOrganization();
        $this->createUser($org, 'Admin', ['email' => 'user@test.com']);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@test.com',
            'token' => 'token-invalido',
            'password' => 'NewPassword2',
            'password_confirmation' => 'NewPassword2',
        ])->assertStatus(422);
    }

    public function test_reset_password_falla_con_contrasena_muy_corta(): void
    {
        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin', ['email' => 'user@test.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@test.com',
            'token' => $token,
            'password' => 'Abc1',
            'password_confirmation' => 'Abc1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // =========================================================
    // LOGOUT
    // =========================================================

    public function test_logout_exitoso(): void
    {
        $org = $this->createOrganization();
        $user = $this->createUser($org, 'Admin');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
