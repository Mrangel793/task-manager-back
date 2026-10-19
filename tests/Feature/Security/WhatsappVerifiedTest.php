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

    public function test_admin_puede_cambiar_whatsapp_verified_de_otro_usuario(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->createUser($org, 'Operador', ['whatsapp_verified' => false]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_verified' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.whatsapp_verified', true);

        $this->assertTrue($this->freshVerified($operador));

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_verified' => false])
            ->assertStatus(200);

        $this->assertFalse($this->freshVerified($operador));
    }

    public function test_cambiar_whatsapp_phone_propio_desverifica(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001112233',
            'whatsapp_verified' => true,
        ]);

        $this->actingAs($operador, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", ['whatsapp_phone' => '+573009998877'])
            ->assertStatus(200);

        $this->assertFalse($this->freshVerified($operador));
    }

    public function test_cambiar_whatsapp_phone_desde_perfil_desverifica(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001112233',
            'whatsapp_verified' => true,
        ]);

        $this->actingAs($operador, 'sanctum')
            ->patchJson('/api/v1/auth/me', ['whatsapp_phone' => '+573009998877'])
            ->assertStatus(200)
            ->assertJsonPath('data.user.whatsapp_verified', false);

        $this->assertFalse($this->freshVerified($operador));
    }

    public function test_reenviar_el_mismo_whatsapp_phone_no_desverifica(): void
    {
        $org = $this->createOrganization();
        $operador = $this->createUser($org, 'Operador', [
            'whatsapp_phone' => '+573001112233',
            'whatsapp_verified' => true,
        ]);

        $this->actingAs($operador, 'sanctum')
            ->patchJson("/api/v1/users/{$operador->id}", [
                'name' => 'Nombre Nuevo',
                'whatsapp_phone' => '+573001112233',
            ])
            ->assertStatus(200);

        $this->assertTrue($this->freshVerified($operador));
    }
}
