<?php

namespace Tests\Feature\Agent;

use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppLinkCode;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgentIdentityTest extends TestCase
{
    private const SECRET = 'test-agent-secret';

    private const PHONE = '+573001234567';

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.hmac_secret' => self::SECRET]);
    }

    /**
     * Petición firmada según el contrato HMAC del agente.
     */
    private function agentCall(string $method, string $uri, array $body = [], array $tweak = []): TestResponse
    {
        $raw = $body === [] ? '' : json_encode($body);
        $timestamp = (string) ($tweak['timestamp'] ?? time());
        $nonce = $tweak['nonce'] ?? bin2hex(random_bytes(16));
        $signature = $tweak['signature'] ?? hash_hmac(
            'sha256',
            implode("\n", [$timestamp, $nonce, $method, $uri, $tweak['signed_body'] ?? $raw]),
            $tweak['secret'] ?? self::SECRET
        );

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AGENT_TIMESTAMP' => $timestamp,
            'HTTP_X_AGENT_NONCE' => $nonce,
            'HTTP_X_AGENT_SIGNATURE' => $signature,
        ], $raw);
    }

    private function whoamiUri(string $phone = self::PHONE): string
    {
        return '/api/agent/v1/whoami?phone='.rawurlencode($phone);
    }

    private function linkedUser(Organization $org, string $role = 'Operador', array $attrs = []): User
    {
        return $this->createUser($org, $role, array_merge([
            'whatsapp_phone' => self::PHONE,
            'whatsapp_verified' => true,
        ], $attrs));
    }

    private function generateCode(User $user): string
    {
        $code = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/whatsapp-link-code')
            ->assertStatus(201)
            ->assertJsonStructure(['success', 'data' => ['code', 'expires_at']])
            ->json('data.code');

        $this->app['auth']->forgetGuards();

        return $code;
    }

    private function freshUser(User $user): User
    {
        return User::withoutGlobalScopes()->findOrFail($user->id);
    }

    // ---------------------------------------------------------------- HMAC

    public function test_sin_secret_configurado_responde_401(): void
    {
        config(['agent.hmac_secret' => '']);

        $this->agentCall('GET', $this->whoamiUri())->assertStatus(401)->assertJsonPath('success', false);
        $this->agentCall('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->assertStatus(401);
        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => '123456'])->assertStatus(401);
    }

    public function test_firma_invalida_responde_401(): void
    {
        $this->linkedUser($this->createOrganization());

        $this->agentCall('GET', $this->whoamiUri(), [], ['secret' => 'otro-secreto'])->assertStatus(401);
        $this->agentCall('GET', $this->whoamiUri(), [], ['signature' => str_repeat('a', 64)])->assertStatus(401);
        $this->agentCall('GET', $this->whoamiUri(), [], ['signature' => 'no-hex'])->assertStatus(401);
    }

    public function test_body_alterado_responde_401(): void
    {
        $this->agentCall('POST', '/api/agent/v1/token', ['phone' => self::PHONE], [
            'signed_body' => json_encode(['phone' => '+573009999999']),
        ])->assertStatus(401);
    }

    public function test_sin_cabeceras_responde_401(): void
    {
        $this->getJson($this->whoamiUri())->assertStatus(401);
    }

    public function test_timestamp_de_6_minutos_responde_401(): void
    {
        $this->linkedUser($this->createOrganization());

        $this->agentCall('GET', $this->whoamiUri(), [], ['timestamp' => time() - 360])->assertStatus(401);
        $this->agentCall('GET', $this->whoamiUri(), [], ['timestamp' => time() + 360])->assertStatus(401);
    }

    public function test_nonce_repetido_responde_401(): void
    {
        $this->linkedUser($this->createOrganization());
        $nonce = bin2hex(random_bytes(16));

        $this->agentCall('GET', $this->whoamiUri(), [], ['nonce' => $nonce])->assertStatus(200);
        $this->agentCall('GET', $this->whoamiUri(), [], ['nonce' => $nonce])->assertStatus(401);
    }

    // ---------------------------------------------------------------- link

    public function test_vincular_con_codigo_valido(): void
    {
        $org = $this->createOrganization(['name' => 'Org Vincular']);
        $user = $this->createUser($org, 'Operador', ['whatsapp_verified' => false]);
        $code = $this->generateCode($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertSame(0, WhatsAppLinkCode::where('code_hash', $code)->count(), 'el código no se guarda en claro');

        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $code])
            ->assertStatus(200)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.organization.id', $org->id)
            ->assertJsonPath('data.organization.name', 'Org Vincular');

        $fresh = $this->freshUser($user);
        $this->assertSame(self::PHONE, $fresh->whatsapp_phone);
        $this->assertTrue($fresh->whatsapp_verified);
        $this->assertNotNull(WhatsAppLinkCode::where('user_id', $user->id)->first()->used_at);
    }

    public function test_usuario_ya_verificado_que_cambia_de_numero_queda_verificado(): void
    {
        $user = $this->createUser($this->createOrganization(), 'Operador', [
            'whatsapp_phone' => '+573005550000',
            'whatsapp_verified' => true,
        ]);
        $code = $this->generateCode($user);

        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $code])->assertStatus(200);

        $fresh = $this->freshUser($user);
        $this->assertSame(self::PHONE, $fresh->whatsapp_phone);
        $this->assertTrue($fresh->whatsapp_verified);
    }

    public function test_reutilizar_codigo_responde_422(): void
    {
        $user = $this->createUser($this->createOrganization());
        $code = $this->generateCode($user);

        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $code])->assertStatus(200);
        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, WhatsAppLinkCode::where('user_id', $user->id)->first()->attempts);
    }

    public function test_codigo_vencido_responde_422(): void
    {
        $user = $this->createUser($this->createOrganization());
        $code = $this->generateCode($user);

        $this->travel(11)->minutes();

        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $code])->assertStatus(422);
        $this->assertNull($this->freshUser($user)->whatsapp_phone);
    }

    public function test_generar_codigo_nuevo_invalida_el_anterior(): void
    {
        $user = $this->createUser($this->createOrganization());
        $old = $this->generateCode($user);
        $new = $this->generateCode($user);

        if ($old !== $new) {
            $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $old])->assertStatus(422);
        }
        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $new])->assertStatus(200);
    }

    public function test_sexto_intento_fallido_bloquea_el_telefono(): void
    {
        $user = $this->createUser($this->createOrganization());
        $code = $this->generateCode($user);
        $wrong = $code === '000000' ? '000001' : '000000';

        for ($i = 1; $i <= 6; $i++) {
            $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $wrong])
                ->assertStatus(422);
        }

        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $code])
            ->assertStatus(429)
            ->assertJsonPath('success', false);

        $this->assertNull($this->freshUser($user)->whatsapp_phone);
    }

    public function test_telefono_de_otro_usuario_responde_409(): void
    {
        $org = $this->createOrganization();
        $this->createUser($org, 'Operador', ['whatsapp_phone' => self::PHONE]);
        $user = $this->createUser($org);
        $code = $this->generateCode($user);

        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => self::PHONE, 'code' => $code])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertNull($this->freshUser($user)->whatsapp_phone);
    }

    public function test_link_con_telefono_no_e164_responde_422(): void
    {
        $this->agentCall('POST', '/api/agent/v1/link', ['phone' => '3001234567', 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    // ---------------------------------------------------------------- whoami

    public function test_whoami_usuario_vinculado(): void
    {
        $org = $this->createOrganization(['name' => 'Org Whoami']);
        $user = $this->linkedUser($org, 'Supervisor');

        $response = $this->agentCall('GET', $this->whoamiUri())
            ->assertStatus(200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.organization.id', $org->id)
            ->assertJsonPath('data.organization.name', 'Org Whoami')
            ->assertJsonPath('data.roles', ['Supervisor'])
            ->assertJsonPath('data.role', 'Supervisor');

        $this->assertContains('create-tasks', $response->json('data.permissions'));
        $this->assertNotContains('delete-tasks', $response->json('data.permissions'));
        $this->assertArrayNotHasKey('email', $response->json('data'));
    }

    public function test_whoami_no_vinculado_inactivo_u_org_inactiva_responde_404(): void
    {
        $this->agentCall('GET', $this->whoamiUri('+573000000001'))->assertStatus(404);

        $this->createUser($this->createOrganization(), 'Operador', [
            'whatsapp_phone' => '+573000000002', 'whatsapp_verified' => false,
        ]);
        $this->agentCall('GET', $this->whoamiUri('+573000000002'))->assertStatus(404);

        $this->createUser($this->createOrganization(), 'Operador', [
            'whatsapp_phone' => '+573000000003', 'whatsapp_verified' => true, 'is_active' => false,
        ]);
        $this->agentCall('GET', $this->whoamiUri('+573000000003'))->assertStatus(404);

        $this->createUser($this->createOrganization(['is_active' => false]), 'Operador', [
            'whatsapp_phone' => '+573000000004', 'whatsapp_verified' => true,
        ]);
        $this->agentCall('GET', $this->whoamiUri('+573000000004'))->assertStatus(404);

        $deletedOrg = $this->createOrganization();
        $this->createUser($deletedOrg, 'Operador', [
            'whatsapp_phone' => '+573000000005', 'whatsapp_verified' => true,
        ]);
        $deletedOrg->delete();
        $this->agentCall('GET', $this->whoamiUri('+573000000005'))->assertStatus(404);
    }

    // ---------------------------------------------------------------- token

    public function test_token_de_agente_respeta_rol_organizacion_y_expira(): void
    {
        $org = $this->createOrganization();
        $admin = $this->createUser($org, 'Admin');
        $operador = $this->linkedUser($org);
        $otro = $this->createUser($org);
        $propia = $this->createTask($org, $admin, $operador);
        $ajena = $this->createTask($org, $admin, $otro);

        $otraOrg = $this->createOrganization();
        $adminOtraOrg = $this->createUser($otraOrg, 'Admin');
        $this->createTask($otraOrg, $adminOtraOrg, $adminOtraOrg);

        $response = $this->agentCall('POST', '/api/agent/v1/token', ['phone' => self::PHONE])
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['token', 'expires_at']]);

        $expiresAt = \Carbon\Carbon::parse($response->json('data.expires_at'));
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $expiresAt->timestamp, 5);

        $token = $response->json('data.token');
        $this->assertSame(['agent'], $operador->tokens()->first()->abilities);

        $ids = collect($this->withToken($token)->getJson('/api/v1/tasks')->assertStatus(200)->json('data'))
            ->pluck('id')->all();
        $this->assertSame([$propia->id], $ids);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson("/api/v1/tasks/{$ajena->id}")->assertStatus(403);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->deleteJson("/api/v1/tasks/{$propia->id}")->assertStatus(403);
        $this->app['auth']->forgetGuards();

        $this->travel(16)->minutes();

        $this->withToken($token)->getJson('/api/v1/tasks')->assertStatus(401);
    }

    public function test_token_no_vinculado_responde_404(): void
    {
        $this->createUser($this->createOrganization(), 'Operador', [
            'whatsapp_phone' => self::PHONE, 'whatsapp_verified' => false,
        ]);

        $this->agentCall('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->assertStatus(404);
    }

    public function test_token_de_agente_no_puede_refrescarse_ni_cambiar_password_ni_generar_codigos(): void
    {
        $this->linkedUser($this->createOrganization());
        $token = $this->agentCall('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/refresh-token')->assertStatus(403);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Password1',
            'password' => 'Password2',
            'password_confirmation' => 'Password2',
        ])->assertStatus(403);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/me/whatsapp-link-code')->assertStatus(403);
    }

    // ---------------------------------------------------------------- unlink

    public function test_desvincular_deja_whoami_en_404_y_revoca_tokens_de_agente(): void
    {
        $user = $this->linkedUser($this->createOrganization());
        $token = $this->agentCall('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->json('data.token');
        $this->agentCall('GET', $this->whoamiUri())->assertStatus(200);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/me/whatsapp')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->app['auth']->forgetGuards();

        $fresh = $this->freshUser($user);
        $this->assertNull($fresh->whatsapp_phone);
        $this->assertFalse($fresh->whatsapp_verified);

        $this->agentCall('GET', $this->whoamiUri())->assertStatus(404);
        $this->agentCall('POST', '/api/agent/v1/token', ['phone' => self::PHONE])->assertStatus(404);
        $this->withToken($token)->getJson('/api/v1/tasks')->assertStatus(401);
    }

    public function test_generar_codigo_sin_autenticar_responde_401(): void
    {
        $this->postJson('/api/v1/me/whatsapp-link-code')->assertStatus(401);
        $this->deleteJson('/api/v1/me/whatsapp')->assertStatus(401);
    }
}
