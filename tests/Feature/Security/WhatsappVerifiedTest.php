<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Tests\TestCase;

class WhatsappVerifiedTest extends TestCase
{
    private function freshVerified(User $user): bool
    {
        return (bool) User::withoutGlobalScopes()->findOrFail($user->id)->whatsapp_verified;
    }

    public function test_operador_no_puede_verificar_su_propio_whatsapp(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador', ['whatsapp_verified' => false]);

        $this->actingAs($operador, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_verified' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_verified');

        $this->assertFalse($this->freshVerified($operador));
    }

    public function test_admin_no_puede_verificar_su_propio_whatsapp(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin', ['whatsapp_verified' => false]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$admin->id}", ['whatsapp_verified' => true])
            ->assertStatus(422);

        $this->assertFalse($this->freshVerified($admin));
    }

    public function test_admin_no_puede_verificar_el_whatsapp_de_otro_usuario(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001112233',
            'whatsapp_verified' => false,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_verified' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_verified');

        $this->assertFalse($this->freshVerified($operador));
    }

    public function test_admin_puede_quitar_la_verificacion_de_otro_usuario(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001112233',
            'whatsapp_verified' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_verified' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.whatsapp_verified', false);

        $this->assertFalse($this->freshVerified($operador));
    }

    /**
     * Ataque: cualquiera crea una org (queda Admin), le pone a un usuario suyo el número
     * de una víctima de otra org y lo marca verificado.
     */
    public function test_admin_no_puede_apartar_verificado_el_numero_de_una_victima_de_otra_org(): void
    {
        config(['agent.hmac_secret' => 'test-agent-secret']);
        $victimPhone = '+573007778888';

        $orgAtacante = $this->createOrganization();
        $admin = $this->createUser($orgAtacante, 'Admin');
        $complice = $this->createUser($orgAtacante, 'Operador');
        $victima = $this->createUser($this->createOrganization(), 'Operador');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$complice->id}", [
                'whatsapp_phone' => $victimPhone,
                'whatsapp_verified' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_verified');

        // Aunque el Admin aparte el número (sin verificar), la víctima puede vincularlo.
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$complice->id}", ['whatsapp_phone' => $victimPhone])
            ->assertStatus(200);
        $this->assertFalse($this->freshVerified($complice));
        $this->app['auth']->forgetGuards();

        $code = $this->actingAs($victima, 'sanctum')
            ->postJson('/api/v1/me/whatsapp-link-code', ['phone' => $victimPhone])
            ->assertStatus(201)
            ->json('data.code');
        $this->app['auth']->forgetGuards();

        $body = json_encode(['phone' => $victimPhone, 'code' => $code]);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $sig = hash_hmac('sha256', implode("\n", [$ts, $nonce, 'POST', '/api/agent/v1/link', $body]), 'test-agent-secret');
        $this->call('POST', '/api/agent/v1/link', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AGENT_TIMESTAMP' => $ts,
            'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => $sig,
        ], $body)->assertStatus(200)->assertJsonPath('data.user_id', $victima->id);

        $this->assertTrue($this->freshVerified($victima));
        $this->assertNull(User::withoutGlobalScopes()->find($complice->id)->whatsapp_phone);
    }

    /**
     * Ataque: el Admin crea un usuario con SU número (le llega el enlace de activación),
     * cambia el WhatsApp al de la víctima y activa: el enlace ya no debe servir.
     */
    public function test_cambiar_whatsapp_phone_invalida_el_enlace_de_activacion_pendiente(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $plainToken = str_repeat('a', 64);
        $nuevo = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001110000',
            'whatsapp_verified' => false,
            'is_active' => false,
        ]);
        $nuevo->forceFill([
            'activation_token' => hash('sha256', $plainToken),
            'activation_token_expires_at' => now()->addHours(48),
        ])->save();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$nuevo->id}", ['whatsapp_phone' => '+573007778888'])
            ->assertStatus(200);
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'Password9',
            'password_confirmation' => 'Password9',
        ])->assertStatus(422);

        $this->assertFalse($this->freshVerified($nuevo));
    }

    public function test_activar_sin_cambiar_el_numero_sigue_verificando(): void
    {
        $org = $this->createOrganization();
        $plainToken = str_repeat('b', 64);
        $nuevo = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001110000',
            'whatsapp_verified' => false,
            'is_active' => false,
        ]);
        $nuevo->forceFill([
            'activation_token' => hash('sha256', $plainToken),
            'activation_token_expires_at' => now()->addHours(48),
        ])->save();

        $this->postJson('/api/v1/auth/activate', [
            'token' => $plainToken,
            'password' => 'Password9',
            'password_confirmation' => 'Password9',
        ])->assertStatus(200);

        $this->assertTrue($this->freshVerified($nuevo));
    }

    public static function rolesQueSeEditanASiMismos(): array
    {
        return [['Operador'], ['Supervisor'], ['Admin']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rolesQueSeEditanASiMismos')]
    public function test_nadie_puede_cambiar_su_propio_whatsapp_phone_desde_users(string $role): void
    {
        $org = $this->createOrganization();
        $user = $this->createUser($org, $role, [
            'whatsapp_phone' => '+573001112233',
            'whatsapp_verified' => true,
        ]);

        foreach (['+573009998877', '+573001112233', null] as $value) {
            $this->actingAs($user, 'sanctum')
                ->patchJson("/api/v1/users/{$user->id}", ['whatsapp_phone' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('whatsapp_phone');
        }

        $this->assertTrue($this->freshVerified($user));
        $this->assertSame('+573001112233', User::withoutGlobalScopes()->find($user->id)->whatsapp_phone);
    }

    public function test_admin_puede_cambiar_el_whatsapp_phone_de_otro_usuario(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001112233',
            'whatsapp_verified' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_phone' => '+573009998877'])
            ->assertStatus(200);

        $this->assertSame('+573009998877', User::withoutGlobalScopes()->find($operador->id)->whatsapp_phone);
        $this->assertFalse($this->freshVerified($operador));
    }

    public function test_supervisor_sin_manage_users_no_cambia_whatsapp_phone_de_otro(): void
    {
        $org = $this->createOrganization();
        $supervisor = $this->createUser($org, 'Supervisor');
        $operador = $this->createUser($org, 'Operador', ['whatsapp_phone' => '+573001112233']);

        $this->actingAs($supervisor, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_phone' => '+573009998877'])
            ->assertStatus(403);

        $this->assertSame('+573001112233', User::withoutGlobalScopes()->find($operador->id)->whatsapp_phone);
    }

    public function test_perfil_no_puede_cambiar_whatsapp_phone(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001112233',
            'whatsapp_verified' => true,
        ]);

        $this->actingAs($operador, 'sanctum')
            ->patchJson('/api/v1/auth/me', ['whatsapp_phone' => '+573009998877'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_phone');

        $this->assertTrue($this->freshVerified($operador));
        $this->assertSame('+573001112233', User::withoutGlobalScopes()->find($operador->id)->whatsapp_phone);
    }

    public function test_admin_con_whatsapp_phone_de_otro_usuario_devuelve_422(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $this->createUser($org, 'Operador', ['whatsapp_phone' => '+573005550000']);
        $operador = $this->createUser($org, 'Operador', ['whatsapp_phone' => '+573001112233']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_phone' => '+573005550000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_phone');

        $this->assertSame('+573001112233', User::withoutGlobalScopes()->find($operador->id)->whatsapp_phone);
    }

    public static function whatsappSinFormatoE164(): array
    {
        return [['3001234567'], ['+5730012'], ['+0573001234567'], ['+5730012345678901']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatsappSinFormatoE164')]
    public function test_admin_con_whatsapp_phone_sin_formato_e164_devuelve_422(string $phone): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_phone' => $phone])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_phone');
    }

    public function test_perfil_rechaza_whatsapp_phone_aunque_sea_el_mismo(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador', ['whatsapp_phone' => '+573001112233']);

        $this->actingAs($operador, 'sanctum')
            ->patchJson('/api/v1/auth/me', ['whatsapp_phone' => '+573001112233'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_phone');
    }

    public function test_admin_reenvia_el_mismo_whatsapp_phone_y_no_desverifica(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001112233',
            'whatsapp_verified' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", [
                'name' => 'Nombre Nuevo',
                'whatsapp_phone' => '+573001112233',
            ])
            ->assertStatus(200);

        $this->assertTrue($this->freshVerified($operador));
    }
}
