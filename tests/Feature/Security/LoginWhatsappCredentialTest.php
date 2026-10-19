<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Tests\TestCase;

class LoginWhatsappCredentialTest extends TestCase
{
    private const PHONE = '+573001112233';

    private function login(string $credential, string $password = 'Password1')
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['credential' => $credential, 'password' => $password]);
    }

    public function test_whatsapp_no_verificado_no_sirve_como_credencial(): void
    {
        $this->createUser($this->createOrganization(), 'Operador', [
            'phone' => '+573009990000',
            'whatsapp_phone' => self::PHONE,
            'whatsapp_verified' => false,
        ]);

        $this->login(self::PHONE)->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_whatsapp_verificado_sigue_sirviendo_como_credencial(): void
    {
        $user = $this->createUser($this->createOrganization(), 'Operador', [
            'phone' => '+573009990000',
            'whatsapp_phone' => self::PHONE,
            'whatsapp_verified' => true,
        ]);

        $this->login(self::PHONE)->assertStatus(200)->assertJsonPath('data.user.id', $user->id);
    }

    public function test_whatsapp_no_verificado_de_otro_no_desplaza_el_login_de_la_victima(): void
    {
        $org = $this->createOrganization();
        $victima = $this->createUser($org, 'Operador', ['phone' => self::PHONE]);
        $this->createUser($org, 'Operador', [
            'phone' => '+573009990000',
            'whatsapp_phone' => self::PHONE,
            'whatsapp_verified' => false,
            'password' => 'Atacante1',
        ]);

        $this->login(self::PHONE)->assertStatus(200)->assertJsonPath('data.user.id', $victima->id);
        $this->login(self::PHONE, 'Atacante1')->assertStatus(401);
        $this->login('3001112233')->assertStatus(200)->assertJsonPath('data.user.id', $victima->id);
    }

    public function test_credencial_que_coincide_con_dos_usuarios_se_rechaza(): void
    {
        $org = $this->createOrganization();
        $this->createUser($org, 'Operador', ['phone' => self::PHONE]);
        $this->createUser($org, 'Operador', [
            'phone' => '+573009990000',
            'whatsapp_phone' => self::PHONE,
            'whatsapp_verified' => true,
            'password' => 'Otro12345',
        ]);

        $this->login(self::PHONE)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Credenciales inválidas.');
        $this->login(self::PHONE, 'Otro12345')->assertStatus(401);
    }

    public function test_usuario_borrado_no_vuelve_ambigua_la_credencial(): void
    {
        $org = $this->createOrganization();
        $victima = $this->createUser($org, 'Operador', ['phone' => self::PHONE]);
        $borrado = $this->createUser($org, 'Operador', ['phone' => '+573009990000']);
        // Datos viejos: un usuario borrado que conserva un WhatsApp verificado igual al phone de otro.
        User::withoutGlobalScopes()->whereKey($borrado->id)->update([
            'whatsapp_phone' => self::PHONE, 'whatsapp_verified' => true, 'deleted_at' => now(),
        ]);

        $this->login(self::PHONE)->assertStatus(200)->assertJsonPath('data.user.id', $victima->id);
    }

    public function test_perfil_no_permite_fijar_whatsapp_phone(): void
    {
        $user = $this->createUser($this->createOrganization(), 'Operador', [
            'whatsapp_phone' => '+573004440000',
            'whatsapp_verified' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/auth/me', ['whatsapp_phone' => '+573005550000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('whatsapp_phone');

        $fresh = User::withoutGlobalScopes()->findOrFail($user->id);
        $this->assertSame('+573004440000', $fresh->whatsapp_phone);
        $this->assertTrue($fresh->whatsapp_verified);
    }

    public function test_perfil_sigue_actualizando_nombre_y_telefono(): void
    {
        $user = $this->createUser($this->createOrganization());

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/auth/me', ['name' => 'Nombre Nuevo', 'phone' => '+573006660000'])
            ->assertStatus(200)
            ->assertJsonPath('data.user.name', 'Nombre Nuevo')
            ->assertJsonPath('data.user.phone', '+573006660000');
    }
}
